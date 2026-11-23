<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\HrLeaveExport;
use App\Services\DeliveriesTeamSqliteService;
use App\Services\FaceIdSqliteService;
use App\Services\HrSqliteService;
use App\Support\ReportAuthSession;
use App\Support\WorkingDays;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class HrController extends Controller
{
    public function __construct(
        private readonly HrSqliteService $hr,
        private readonly DeliveriesTeamSqliteService $deliveries,
        private readonly FaceIdSqliteService $faceId,
    ) {}

    public function employees(Request $request): View
    {
        $this->requireEmployees();
        $names = $this->sourceNames();
        $employees = $this->hr->applySourceNames($this->hr->listEmployees(), $names);
        $editId = (int) $request->query('edit', 0);
        $editing = null;
        if ($editId > 0) {
            foreach ($employees as $employee) {
                if ((int) $employee->id === $editId) {
                    $editing = $employee;
                    break;
                }
            }
        }

        return view('reports.hr.employees', [
            'employees' => $employees,
            'editing' => $editing,
            'sourceOptions' => $this->sourceOptions($this->hr->linkedSourceKeys()),
            'sourceError' => $this->sourceError,
        ]);
    }

    public function storeEmployee(Request $request): RedirectResponse
    {
        $this->requireEmployees();
        $validated = $request->validate([
            'source_ref' => ['required', 'string', 'max:80'],
            'full_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:300'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hire_date' => ['nullable', 'date'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'emergency_name' => ['nullable', 'string', 'max:200'],
            'emergency_relationship' => ['nullable', 'string', 'max:80'],
            'emergency_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $sourceRef = (string) $validated['source_ref'];
        if ($sourceRef === HrSqliteService::SOURCE_OTHER) {
            $sourceType = HrSqliteService::SOURCE_OTHER;
            $sourceId = null;
            $name = trim((string) ($validated['full_name'] ?? ''));
            if ($name === '') {
                return back()->withInput()->withErrors(['full_name' => 'Name is required.']);
            }
        } elseif (preg_match('/^(delivery_driver|delivery_companion|face_id):(\d+)$/', $sourceRef, $match) === 1) {
            $sourceType = $match[1];
            $sourceId = (int) $match[2];
            $name = $this->sourceNames()[$sourceType.':'.$sourceId] ?? '';
            if ($name === '') {
                return back()->withInput()->withErrors(['source_ref' => 'That person was not found.']);
            }
        } else {
            return back()->withInput()->withErrors(['source_ref' => 'Choose a person.']);
        }

        try {
            $this->hr->createEmployee([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'full_name' => $name,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'job_title' => $validated['job_title'] ?? null,
                'hire_date' => $validated['hire_date'] ?? null,
                'national_id' => $validated['national_id'] ?? null,
                'emergency_name' => $validated['emergency_name'] ?? null,
                'emergency_relationship' => $validated['emergency_relationship'] ?? null,
                'emergency_phone' => $validated['emergency_phone'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['source_ref' => $e->getMessage()]);
        }

        return redirect()->route('reports.hr.employees.index')->with('status', 'Employee saved.');
    }

    public function updateEmployee(Request $request, int $employee): RedirectResponse
    {
        $this->requireEmployees();
        $existing = $this->hr->findEmployee($employee);
        if ($existing === null) {
            return redirect()->route('reports.hr.employees.index')->with('error', 'That employee was not found.');
        }

        $validated = $request->validate([
            'full_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:300'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hire_date' => ['nullable', 'date'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'emergency_name' => ['nullable', 'string', 'max:200'],
            'emergency_relationship' => ['nullable', 'string', 'max:80'],
            'emergency_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $names = $this->sourceNames();
        $presented = $this->hr->applySourceNames([$existing], $names)[0];
        $name = (string) ($presented->source_type ?? '') === HrSqliteService::SOURCE_OTHER
            ? trim((string) ($validated['full_name'] ?? ''))
            : (string) ($presented->full_name ?? '');
        if ($name === '') {
            return back()->withInput()->withErrors(['full_name' => 'Name is required.']);
        }

        try {
            $this->hr->updateEmployee($employee, [
                'full_name' => $name,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'job_title' => $validated['job_title'] ?? null,
                'hire_date' => $validated['hire_date'] ?? null,
                'national_id' => $validated['national_id'] ?? null,
                'emergency_name' => $validated['emergency_name'] ?? null,
                'emergency_relationship' => $validated['emergency_relationship'] ?? null,
                'emergency_phone' => $validated['emergency_phone'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('reports.hr.employees.index')->with('status', 'Employee updated.');
    }

    public function leave(Request $request): View
    {
        $this->requireLeavePage();
        $status = $this->statusFilter($request);

        return view('reports.hr.leave', [
            'requests' => $this->leaveRows($status),
            'status' => $status,
            'employees' => $this->activeEmployees(),
            'holidayDates' => $this->holidayDates(),
            'canFile' => ReportAuthSession::canAccessReport('hr-leave'),
            'canApprove' => ReportAuthSession::canAccessReport('hr-leave-approve'),
        ]);
    }

    public function storeLeave(Request $request): RedirectResponse
    {
        $this->requireFileLeave();
        $hourly = (string) $request->input('leave_type') === 'hourly';
        $rules = [
            'employee_id' => ['required', 'integer', 'min:1'],
            'leave_type' => ['required', 'in:hourly,annual,sick,unpaid,other'],
            'start_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
        if ($hourly) {
            $rules['start_time'] = ['required', 'string', 'max:8'];
            $rules['end_time'] = ['required', 'string', 'max:8'];
        } else {
            $rules['end_date'] = ['required', 'date', 'after_or_equal:start_date'];
        }
        $validated = $request->validate($rules);

        try {
            $this->hr->createLeave(
                (int) $validated['employee_id'],
                (string) $validated['leave_type'],
                (string) $validated['start_date'],
                $hourly ? (string) $validated['start_date'] : (string) $validated['end_date'],
                (string) $validated['reason'],
                ReportAuthSession::userId(),
                (string) (ReportAuthSession::username() ?? 'User'),
                $hourly ? (string) ($validated['start_time'] ?? '') : null,
                $hourly ? (string) ($validated['end_time'] ?? '') : null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['employee_id' => $e->getMessage()]);
        }

        return redirect()->route('reports.hr.leave.index')->with('status', 'Leave request filed.');
    }

    public function decideLeave(Request $request, int $leave): RedirectResponse
    {
        $this->requireApproveLeave();
        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
            'filter_status' => ['nullable', 'in:all,pending,approved,rejected'],
        ]);

        try {
            $this->hr->decideLeave(
                $leave,
                (string) $validated['status'],
                $validated['decision_note'] ?? null,
                ReportAuthSession::userId(),
                (string) (ReportAuthSession::username() ?? 'Manager'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->redirectToLeave((string) ($validated['filter_status'] ?? 'all'))->with('error', $e->getMessage());
        }

        $label = $validated['status'] === 'approved' ? 'approved' : 'rejected';

        return $this->redirectToLeave((string) ($validated['filter_status'] ?? 'all'))
            ->with('status', 'Leave request '.$label.'.');
    }

    public function printLeave(int $leave): View
    {
        $this->requireLeavePage();
        $row = $this->leaveRow($leave);
        if ($row === null) {
            abort(404);
        }

        return view('reports.hr.leave-print', ['requestRow' => $row]);
    }

    public function leavePdf(int $leave): Response
    {
        $this->requireLeavePage();
        $row = $this->leaveRow($leave);
        if ($row === null) {
            abort(404);
        }

        $branding = InvoiceBrandingSettingsController::getSettings();
        $pdf = Pdf::loadView('reports.hr.leave-pdf', [
            'requestRow' => $row,
            ...ReportPdfBranding::viewData($branding),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('leave-request-'.$leave.'.pdf');
    }

    public function exportPdf(Request $request): Response|RedirectResponse
    {
        $this->requireLeavePage();
        $status = $this->statusFilter($request);

        try {
            $rows = $this->leaveRows($status);
        } catch (Throwable $e) {
            Log::error('hr.leave_pdf_failed', ['message' => $e->getMessage()]);

            return $this->redirectToLeave($status)->with('error', 'Could not build the leave PDF.');
        }

        $branding = InvoiceBrandingSettingsController::getSettings();
        $pdf = Pdf::loadView('reports.hr.leave-list-pdf', [
            'rows' => $rows,
            'status' => $status,
            'statusLabel' => $status === 'all' ? 'All' : HrSqliteService::statusLabel($status),
            ...ReportPdfBranding::viewData($branding),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('leave-requests-'.now()->format('Y-m-d').'.pdf');
    }

    public function exportCsv(Request $request): BinaryFileResponse|RedirectResponse
    {
        $this->requireLeavePage();
        $status = $this->statusFilter($request);

        try {
            $rows = $this->leaveRows($status);
        } catch (Throwable $e) {
            Log::error('hr.leave_csv_failed', ['message' => $e->getMessage()]);

            return $this->redirectToLeave($status)->with('error', 'Could not build the leave CSV.');
        }

        return Excel::download(
            new HrLeaveExport($rows),
            'leave-requests-'.now()->format('Y-m-d').'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    private ?string $sourceError = null;

    /** @var array<string, string>|null */
    private ?array $sourceNameCache = null;

    /**
     * @return array<string, string>
     */
    private function sourceNames(): array
    {
        if ($this->sourceNameCache !== null) {
            return $this->sourceNameCache;
        }

        $names = [];
        try {
            foreach ($this->deliveries->listDrivers() as $driver) {
                $name = trim((string) ($driver->full_name ?? ''));
                if ($name !== '') {
                    $names[HrSqliteService::SOURCE_DRIVER.':'.(int) $driver->id] = $name;
                }
            }
            foreach ($this->deliveries->listCompanions() as $companion) {
                $name = trim((string) ($companion->full_name ?? ''));
                if ($name !== '') {
                    $names[HrSqliteService::SOURCE_COMPANION.':'.(int) $companion->id] = $name;
                }
            }
        } catch (Throwable $e) {
            Log::warning('hr.delivery_people_unavailable', ['message' => $e->getMessage()]);
            $this->sourceError = 'Delivery people could not be loaded. You can still add someone else.';
        }

        try {
            foreach ($this->faceId->listEmployees() as $employee) {
                $name = trim((string) ($employee->name ?? ''));
                if ($name !== '') {
                    $names[HrSqliteService::SOURCE_FACE.':'.(int) $employee->id] = $name;
                }
            }
        } catch (Throwable $e) {
            Log::warning('hr.face_id_people_unavailable', ['message' => $e->getMessage()]);
            $this->sourceError = 'Face ID people could not be loaded. You can still add someone else.';
        }

        $this->sourceNameCache = $names;

        return $names;
    }

    /**
     * @param  list<string>  $linkedKeys
     * @return list<array{value: string, label: string, group: string}>
     */
    private function sourceOptions(array $linkedKeys): array
    {
        $linked = array_flip($linkedKeys);
        $options = [];
        try {
            foreach ($this->deliveries->listDrivers() as $driver) {
                $value = HrSqliteService::SOURCE_DRIVER.':'.(int) $driver->id;
                if (isset($linked[$value])) {
                    continue;
                }
                $name = trim((string) ($driver->full_name ?? ''));
                if ($name === '') {
                    continue;
                }
                $car = trim((string) ($driver->car_number ?? ''));
                $options[] = [
                    'value' => $value,
                    'label' => $car !== '' ? $name.' ('.$car.')' : $name,
                    'group' => 'Drivers',
                ];
            }
            foreach ($this->deliveries->listCompanions() as $companion) {
                $value = HrSqliteService::SOURCE_COMPANION.':'.(int) $companion->id;
                if (isset($linked[$value])) {
                    continue;
                }
                $name = trim((string) ($companion->full_name ?? ''));
                if ($name === '') {
                    continue;
                }
                $options[] = ['value' => $value, 'label' => $name, 'group' => 'Companions'];
            }
        } catch (Throwable $e) {
            Log::warning('hr.delivery_options_unavailable', ['message' => $e->getMessage()]);
        }

        try {
            foreach ($this->faceId->listEmployees() as $employee) {
                $value = HrSqliteService::SOURCE_FACE.':'.(int) $employee->id;
                if (isset($linked[$value])) {
                    continue;
                }
                $name = trim((string) ($employee->name ?? ''));
                if ($name === '') {
                    continue;
                }
                $code = trim((string) ($employee->employee_code ?? ''));
                $options[] = [
                    'value' => $value,
                    'label' => $code !== '' ? $name.' ('.$code.')' : $name,
                    'group' => 'Face ID',
                ];
            }
        } catch (Throwable $e) {
            Log::warning('hr.face_id_options_unavailable', ['message' => $e->getMessage()]);
        }

        return $options;
    }

    /**
     * @return list<object>
     */
    private function activeEmployees(): array
    {
        $rows = [];
        foreach ($this->hr->applySourceNames($this->hr->listEmployees(), $this->sourceNames()) as $employee) {
            if ((int) ($employee->is_active ?? 0) === 1) {
                $rows[] = $employee;
            }
        }

        return $rows;
    }

    /**
     * @return list<object>
     */
    private function leaveRows(string $status): array
    {
        $names = $this->sourceNames();
        $rows = [];
        foreach ($this->hr->listLeave($status) as $row) {
            $rows[] = $this->hr->presentLeave($row, $names);
        }

        return $rows;
    }

    private function leaveRow(int $id): ?object
    {
        $row = $this->hr->findLeave($id);
        if ($row === null) {
            return null;
        }

        return $this->hr->presentLeave($row, $this->sourceNames());
    }

    /**
     * @return list<string>
     */
    private function holidayDates(): array
    {
        try {
            return WorkingDays::holidayDatesBetween(
                now()->subYear()->startOfYear(),
                now()->addYears(2)->endOfYear()
            );
        } catch (Throwable) {
            return [];
        }
    }

    private function statusFilter(Request $request): string
    {
        $status = trim((string) $request->query('status', 'all'));

        return in_array($status, ['all', 'pending', 'approved', 'rejected'], true) ? $status : 'all';
    }

    private function redirectToLeave(string $status): RedirectResponse
    {
        return redirect()->route('reports.hr.leave.index', $status === 'all' ? [] : ['status' => $status]);
    }

    private function requireEmployees(): void
    {
        if (! ReportAuthSession::canAccessReport('hr-employees')) {
            abort(403, 'You do not have access to this report.');
        }
    }

    private function requireLeavePage(): void
    {
        if (! ReportAuthSession::canAccessReport('hr-leave') && ! ReportAuthSession::canAccessReport('hr-leave-approve')) {
            abort(403, 'You do not have access to this report.');
        }
    }

    private function requireFileLeave(): void
    {
        if (! ReportAuthSession::canAccessReport('hr-leave')) {
            abort(403, 'You do not have permission to file leave.');
        }
    }

    private function requireApproveLeave(): void
    {
        if (! ReportAuthSession::canAccessReport('hr-leave-approve')) {
            abort(403, 'You do not have permission to approve leave.');
        }
    }
}
