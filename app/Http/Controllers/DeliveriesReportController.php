<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\DeliveriesReportExport;
use App\Http\Requests\DeliveriesReportRequest;
use App\Repositories\DeliveriesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\DeliveryInvoicePdfExtractor;
use App\Services\DeliveriesTeamSqliteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DeliveriesReportController extends Controller
{
    public function __construct(
        private readonly DeliveriesReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly DeliveriesTeamSqliteService $teams,
        private readonly DeliveryInvoicePdfExtractor $pdfExtractor
    ) {
    }

    public function index(DeliveriesReportRequest $request): View
    {
        $today = Carbon::now()->toDateString();
        $input = array_merge([
            'date_from' => $today,
            'date_to' => $today,
            'per_page' => 25,
            'storage' => '',
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $perPage = (int) ($input['per_page'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $storage = trim((string) ($input['storage'] ?? ''));
        $deliveryStatus = trim((string) ($input['delivery_status'] ?? ''));
        $teamId = (int) ($input['team_id'] ?? 0);
        $activeTab = (string) ($input['tab'] ?? 'report');
        $teamDate = (string) ($input['team_date'] ?? $today);
        $includeAmount = (bool) ($input['include_amount'] ?? false);
        $includeWeight = (bool) ($input['include_weight'] ?? false);
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        $storageOptions = $this->repository->getStorageOptions();
        $cityOptions = $this->visitsRepository->getCityOptions();
        $drivers = [];
        $companions = [];
        $teamsForSetupDate = [];
        $teamsByDate = [];
        $assignmentMap = [];
        $teamFilterOptions = [];
        $batchResult = session('batch_result');

        try {
            $drivers = $this->teams->listDrivers();
            $companions = $this->teams->listCompanions();
            $teamsForSetupDate = $this->teams->listDailyTeamsForDate($teamDate);
            $teamsByDate = $this->teams->listDailyTeamsByDateRange($dateFrom, $dateTo);
            foreach ($teamsByDate as $dateRows) {
                foreach ($dateRows as $teamRow) {
                    $teamFilterOptions[] = $teamRow;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Deliveries local team setup unavailable.', ['message' => $e->getMessage()]);
        }

        $invoiceIds = null;
        if ($teamId > 0) {
            try {
                $invoiceIds = $this->teams->invoiceIdsForTeamInDateRange($teamId, $dateFrom, $dateTo);
            } catch (Throwable $e) {
                Log::warning('Deliveries team filter failed.', ['message' => $e->getMessage()]);
                $invoiceIds = [];
            }
        }

        try {
            $rows = $this->repository->getReport(
                $dateFrom,
                $dateTo,
                $cities,
                $storage !== '' ? $storage : null,
                $deliveryStatus !== '' ? $deliveryStatus : null,
                $invoiceIds,
                $page,
                $perPage
            );
            $invoiceIdsFromRows = [];
            foreach ($rows->items() as $row) {
                $invoiceIdsFromRows[] = trim((string) ($row->invoice_id ?? ''));
            }
            if ($invoiceIdsFromRows !== []) {
                try {
                    $assignmentMap = $this->teams->assignmentsByInvoiceIds($invoiceIdsFromRows);
                } catch (Throwable $e) {
                    Log::warning('Deliveries team mapping failed.', ['message' => $e->getMessage()]);
                    $assignmentMap = [];
                }
            }
            foreach ($rows->items() as $row) {
                $invoiceId = trim((string) ($row->invoice_id ?? ''));
                $assigned = $assignmentMap[$invoiceId] ?? null;
                $row->team_id = $assigned !== null ? (int) ($assigned->team_id ?? 0) : null;
                $row->team_name = $assigned !== null ? $this->teams->teamLabel($assigned) : '';
            }
        } catch (Throwable $e) {
            Log::error('Deliveries report failed.', ['message' => $e->getMessage()]);

            return view('reports.deliveries.index', [
                'rows' => null,
                'filters' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'per_page' => $perPage,
                    'cities' => $cities,
                    'storage' => $storage,
                    'delivery_status' => $deliveryStatus,
                    'team_id' => $teamId > 0 ? $teamId : '',
                    'tab' => $activeTab,
                    'team_date' => $teamDate,
                    'include_amount' => $includeAmount,
                    'include_weight' => $includeWeight,
                ],
                'storageOptions' => $storageOptions,
                'cityOptions' => $cityOptions,
                'drivers' => $drivers,
                'companions' => $companions,
                'teamsForSetupDate' => $teamsForSetupDate,
                'teamsByDate' => $teamsByDate,
                'teamFilterOptions' => $teamFilterOptions,
                'batchResult' => $batchResult,
                'errorMessage' => 'Unable to load deliveries report. Check logs and try again.',
            ]);
        }

        return view('reports.deliveries.index', [
            'rows' => $rows,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'per_page' => $perPage,
                'cities' => $cities,
                'storage' => $storage,
                'delivery_status' => $deliveryStatus,
                'team_id' => $teamId > 0 ? $teamId : '',
                'tab' => $activeTab,
                'team_date' => $teamDate,
                'include_amount' => $includeAmount,
                'include_weight' => $includeWeight,
            ],
            'storageOptions' => $storageOptions,
            'cityOptions' => $cityOptions,
            'drivers' => $drivers,
            'companions' => $companions,
            'teamsForSetupDate' => $teamsForSetupDate,
            'teamsByDate' => $teamsByDate,
            'teamFilterOptions' => $teamFilterOptions,
            'batchResult' => $batchResult,
            'errorMessage' => null,
        ]);
    }

    public function exportPdf(DeliveriesReportRequest $request): Response|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $storage = trim((string) ($input['storage'] ?? ''));
        $deliveryStatus = trim((string) ($input['delivery_status'] ?? ''));
        $teamId = (int) ($input['team_id'] ?? 0);
        $includeAmount = (bool) ($input['include_amount'] ?? false);
        $includeWeight = (bool) ($input['include_weight'] ?? false);
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $invoiceIds = null;
        if ($teamId > 0) {
            try {
                $invoiceIds = $this->teams->invoiceIdsForTeamInDateRange($teamId, $dateFrom, $dateTo);
            } catch (Throwable) {
                $invoiceIds = [];
            }
        }

        try {
            $rows = $this->repository->exportRows(
                $dateFrom,
                $dateTo,
                $cities,
                $storage !== '' ? $storage : null,
                $deliveryStatus !== '' ? $deliveryStatus : null,
                $invoiceIds
            );
            $invoiceIdsFromRows = array_values(array_filter(array_map(
                static fn (object $row): string => trim((string) ($row->invoice_id ?? '')),
                $rows
            )));
            try {
                $assignmentMap = $this->teams->assignmentsByInvoiceIds($invoiceIdsFromRows);
            } catch (Throwable $e) {
                Log::warning('Deliveries PDF team mapping failed.', ['message' => $e->getMessage()]);
                $assignmentMap = [];
            }
            foreach ($rows as $row) {
                $invoiceId = trim((string) ($row->invoice_id ?? ''));
                $assigned = $assignmentMap[$invoiceId] ?? null;
                $row->team_name = $assigned !== null ? $this->teams->teamLabel($assigned) : '';
            }
        } catch (Throwable $e) {
            Log::error('Deliveries PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.deliveries.index', $request->query()))
                ->with('error', 'Could not export PDF.');
        }

        $pdf = Pdf::loadView('reports.deliveries.pdf', [
            'rows' => $rows,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'cities' => $cities,
            'storage' => $storage,
            'deliveryStatus' => $deliveryStatus,
            'teamId' => $teamId > 0 ? $teamId : null,
            'includeAmount' => $includeAmount,
            'includeWeight' => $includeWeight,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('deliveries-'.$dateFrom.'-'.$dateTo.'.pdf');
    }

    public function exportCsv(DeliveriesReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $storage = trim((string) ($input['storage'] ?? ''));
        $deliveryStatus = trim((string) ($input['delivery_status'] ?? ''));
        $teamId = (int) ($input['team_id'] ?? 0);
        $includeAmount = (bool) ($input['include_amount'] ?? false);
        $includeWeight = (bool) ($input['include_weight'] ?? false);
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $invoiceIds = null;
        if ($teamId > 0) {
            try {
                $invoiceIds = $this->teams->invoiceIdsForTeamInDateRange($teamId, $dateFrom, $dateTo);
            } catch (Throwable) {
                $invoiceIds = [];
            }
        }

        try {
            $rows = $this->repository->exportRows(
                $dateFrom,
                $dateTo,
                $cities,
                $storage !== '' ? $storage : null,
                $deliveryStatus !== '' ? $deliveryStatus : null,
                $invoiceIds
            );
            $invoiceIdsFromRows = array_values(array_filter(array_map(
                static fn (object $row): string => trim((string) ($row->invoice_id ?? '')),
                $rows
            )));
            try {
                $assignmentMap = $this->teams->assignmentsByInvoiceIds($invoiceIdsFromRows);
            } catch (Throwable $e) {
                Log::warning('Deliveries CSV team mapping failed.', ['message' => $e->getMessage()]);
                $assignmentMap = [];
            }
            foreach ($rows as $row) {
                $invoiceId = trim((string) ($row->invoice_id ?? ''));
                $assigned = $assignmentMap[$invoiceId] ?? null;
                $row->team_name = $assigned !== null ? $this->teams->teamLabel($assigned) : '';
            }
        } catch (Throwable $e) {
            Log::error('Deliveries CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.deliveries.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        return Excel::download(
            new DeliveriesReportExport($rows, $includeAmount, $includeWeight),
            'deliveries-'.$dateFrom.'-'.$dateTo.'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function saveDriver(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'driver_name' => ['required', 'string', 'max:200'],
            'car_number' => ['nullable', 'string', 'max:100'],
            'car_model' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $this->teams->addDriver(
                trim((string) $validated['driver_name']),
                trim((string) ($validated['car_number'] ?? '')),
                trim((string) ($validated['car_model'] ?? ''))
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($request->query(), [
            'tab' => 'setup',
        ]))->with('status', 'Driver saved.');
    }

    public function saveCompanion(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'companion_name' => ['required', 'string', 'max:200'],
        ]);

        try {
            $this->teams->addCompanion(trim((string) $validated['companion_name']));
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($request->query(), [
            'tab' => 'setup',
        ]))->with('status', 'Companion saved.');
    }

    public function saveDailyTeam(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'team_date' => ['required', 'date'],
            'driver_id' => ['required', 'integer', 'min:1'],
            'companion_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->teams->addDailyTeam(
                (string) $validated['team_date'],
                (int) $validated['driver_id'],
                (int) $validated['companion_id']
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($request->query(), [
            'tab' => 'daily-teams',
            'team_date' => (string) $validated['team_date'],
        ]))->with('status', 'Daily team saved.');
    }

    public function assignInvoiceTeam(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'string', 'max:100'],
            'document_date' => ['required', 'date'],
            'team_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->teams->assignInvoiceTeam(
                trim((string) $validated['invoice_id']),
                (string) $validated['document_date'],
                (int) $validated['team_id']
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', $request->query())->with('status', 'Invoice team assignment saved.');
    }

    public function batchAssignFromPdf(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'batch_pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'],
        ]);

        try {
            $numbers = $this->pdfExtractor->extractInvoiceNumbers(
                (string) $request->file('batch_pdf')->getRealPath()
            );
            $matches = $this->repository->findInvoicesByInvoiceNumbers(
                (string) $validated['date_from'],
                (string) $validated['date_to'],
                $numbers
            );

            $assignedCount = 0;
            $matchedNumbers = [];
            foreach ($matches as $row) {
                $invoiceId = trim((string) ($row->invoice_id ?? ''));
                if ($invoiceId === '') {
                    continue;
                }
                $this->teams->assignInvoiceTeam(
                    $invoiceId,
                    (string) ($row->document_date ?? $validated['date_from']),
                    (int) $validated['team_id']
                );
                $assignedCount++;
                $matchedNumbers[] = trim((string) ($row->invoice_no ?? ''));
            }

            $unmatchedCount = count(array_diff(
                array_values(array_unique($numbers)),
                array_values(array_unique(array_filter($matchedNumbers)))
            ));
        } catch (Throwable $e) {
            return redirect()->route('reports.deliveries.index', array_merge($request->query(), [
                'tab' => 'batch-assignment',
            ]))->with('error', 'Batch assignment failed: '.$e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($request->query(), [
            'tab' => 'batch-assignment',
        ]))->with('batch_result', [
            'team_id' => (int) $validated['team_id'],
            'extracted_count' => count($numbers),
            'matched_count' => count($matches),
            'assigned_count' => $assignedCount,
            'unmatched_count' => $unmatchedCount,
        ])->with('status', 'Batch assignment completed.');
    }
}

