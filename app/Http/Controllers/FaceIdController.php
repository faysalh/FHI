<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\FaceIdAttendanceExport;
use App\Http\Requests\FaceIdAttendanceExportRequest;
use App\Http\Requests\FaceIdEmployeeStoreRequest;
use App\Http\Requests\FaceIdEmployeeUpdateRequest;
use App\Http\Requests\FaceIdFaceDescriptorRequest;
use App\Http\Requests\FaceIdIndexRequest;
use App\Services\FaceIdSqliteService;
use App\Support\ReportPdfBranding;
use App\Support\ReportingTime;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class FaceIdController extends Controller
{
    public function __construct(
        private readonly FaceIdSqliteService $faceId
    ) {}

    public function index(FaceIdIndexRequest $request): View
    {
        $filters = $request->filters();
        $tab = $filters['tab'];

        $employees = [];
        $attendance = [];
        $kioskUrl = '';
        $errorMessage = null;
        $faceIdReady = false;

        try {
            $this->faceId->ensureReady();
            $faceIdReady = true;
            $employees = $this->faceId->listEmployees();
            if ($tab === 'logs') {
                $attendance = $this->faceId->listAttendance($filters['date_from'], $filters['date_to']);
            }
            if ($tab === 'kiosk') {
                $kioskUrl = route('face-id.kiosk.show', ['token' => $this->faceId->getKioskToken()]);
            }
        } catch (Throwable $e) {
            Log::warning('Face ID page data unavailable.', ['message' => $e->getMessage()]);
            $errorMessage = 'Face ID data is temporarily unavailable.';
        }

        return view('reports.face-id.index', [
            'filters' => $filters,
            'employees' => $employees,
            'attendance' => $attendance,
            'kioskUrl' => $kioskUrl,
            'errorMessage' => $errorMessage,
            'faceIdReady' => $faceIdReady,
            'faceIdDatabasePath' => (string) config('database.connections.face_id_sqlite.database'),
            'faceIdEmployeeCount' => count($employees),
            'reportingTimezone' => ReportingTime::timezone(),
            'reportingNow' => ReportingTime::now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function storeEmployee(FaceIdEmployeeStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->faceId->createEmployee(
                (string) $validated['name'],
                isset($validated['employee_code']) ? (string) $validated['employee_code'] : null
            );
        } catch (Throwable $e) {
            return $this->redirectTab($request, 'employees')->with('error', $e->getMessage());
        }

        return $this->redirectTab($request, 'employees')->with('status', 'Employee added.');
    }

    public function updateEmployee(FaceIdEmployeeUpdateRequest $request, int $employee): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->faceId->updateEmployee(
                $employee,
                (string) $validated['name'],
                isset($validated['employee_code']) ? (string) $validated['employee_code'] : null,
                (bool) ($validated['is_active'] ?? true)
            );
        } catch (Throwable $e) {
            return $this->redirectTab($request, 'employees')->with('error', $e->getMessage());
        }

        return $this->redirectTab($request, 'employees')->with('status', 'Employee updated.');
    }

    public function destroyEmployee(Request $request, int $employee): RedirectResponse
    {
        try {
            $this->faceId->deleteEmployee($employee);
        } catch (Throwable $e) {
            return $this->redirectTab($request, 'employees')->with('error', $e->getMessage());
        }

        return $this->redirectTab($request, 'employees')->with('status', 'Employee removed.');
    }

    public function storeFace(FaceIdFaceDescriptorRequest $request, int $employee): JsonResponse
    {
        try {
            $this->faceId->saveFaceDescriptor($employee, $request->descriptor());
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'errors' => ['descriptor' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Face enrolled.']);
    }

    public function destroyFace(Request $request, int $employee): RedirectResponse
    {
        try {
            $this->faceId->clearFaceDescriptor($employee);
        } catch (Throwable $e) {
            return $this->redirectTab($request, 'employees')->with('error', $e->getMessage());
        }

        return $this->redirectTab($request, 'employees')->with('status', 'Face enrollment cleared.');
    }

    public function regenerateKioskToken(Request $request): RedirectResponse
    {
        try {
            $this->faceId->regenerateKioskToken();
        } catch (Throwable $e) {
            return $this->redirectTab($request, 'kiosk')->with('error', $e->getMessage());
        }

        return $this->redirectTab($request, 'kiosk')->with('status', 'Kiosk link regenerated. Old links no longer work.');
    }

    public function exportPdf(FaceIdAttendanceExportRequest $request): Response
    {
        $filters = $request->filters();
        $rows = $this->faceId->listAttendance($filters['date_from'], $filters['date_to']);

        $pdf = Pdf::loadView('reports.face-id.pdf', [
            'rows' => $rows,
            'filters' => $filters,
            'branding' => ReportPdfBranding::forCurrentRequest(),
        ])->setPaper('a4', 'landscape');

        $filename = 'face-id-attendance-'.$filters['date_from'].'-'.$filters['date_to'].'.pdf';

        return $pdf->download($filename);
    }

    public function exportCsv(FaceIdAttendanceExportRequest $request): BinaryFileResponse
    {
        $filters = $request->filters();
        $rows = $this->faceId->listAttendance($filters['date_from'], $filters['date_to']);
        $filename = 'face-id-attendance-'.$filters['date_from'].'-'.$filters['date_to'].'.csv';

        return Excel::download(new FaceIdAttendanceExport($rows), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    private function redirectTab(Request $request, string $tab): RedirectResponse
    {
        $params = ['tab' => $tab];
        if ($tab === 'logs') {
            if ($request->filled('date_from')) {
                $params['date_from'] = $request->input('date_from');
            }
            if ($request->filled('date_to')) {
                $params['date_to'] = $request->input('date_to');
            }
        }

        return redirect()->route('reports.face-id.index', $params);
    }
}
