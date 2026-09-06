<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\DeliveriesReportExport;
use App\Http\Requests\DeliveriesReportRequest;
use App\Repositories\DeliveriesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\DeliveriesTeamSqliteService;
use App\Services\DeliveryInvoicePdfExtractor;
use App\Services\ReportAssemblyPriorityService;
use App\Support\DeliveriesReportAccess;
use App\Support\ReportAuthSession;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        private readonly DeliveryInvoicePdfExtractor $pdfExtractor,
        private readonly ReportAssemblyPriorityService $assemblyPriority
    ) {}

    public function index(DeliveriesReportRequest $request): View|RedirectResponse
    {
        if ($request->query('tab') === 'receipts') {
            return redirect()->route('reports.accounting.index', ['tab' => 'receipts']);
        }

        $today = Carbon::now()->toDateString();
        $input = array_merge([
            'date_from' => $today,
            'date_to' => $today,
            'per_page' => 250,
            'storage' => '',
        ], $request->validated());

        $activeTab = (string) ($input['tab'] ?? 'report');
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $perPage = (int) ($input['per_page'] ?? 250);
        $page = (int) ($input['page'] ?? 1);
        $storage = trim((string) ($input['storage'] ?? ''));
        $deliveryStatus = trim((string) ($input['delivery_status'] ?? ''));
        $teamId = (int) ($input['team_id'] ?? 0);
        $teamDate = (string) ($input['team_date'] ?? $today);
        $invoiceSearch = trim((string) ($input['invoice_search'] ?? ''));
        $includeAmount = (bool) ($input['include_amount'] ?? false);
        $includeWeight = (bool) ($input['include_weight'] ?? false);
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $salesmanIds = $this->repository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );
        $deliveriesAccess = ReportAuthSession::deliveriesAccess();

        $storageOptions = $this->repository->getStorageOptions();
        $cityOptions = $this->visitsRepository->getCityOptions();
        $salesmanOptions = $this->visitsRepository->getSalesmanOptions();
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
            if ($activeTab === 'batch-assignment') {
                $teamFilterOptions = $teamsForSetupDate;
            } else {
                foreach ($teamsByDate as $dateRows) {
                    foreach ($dateRows as $teamRow) {
                        $teamFilterOptions[] = $teamRow;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Deliveries local team setup unavailable.', ['message' => $e->getMessage()]);
        }

        $assignmentTeamOptions = $this->buildAssignmentTeamOptions($teamFilterOptions, $teamsForSetupDate);

        $invoiceIds = null;
        $applyDateFilter = true;
        $invoiceSearchNotFound = false;
        [$invoiceIds, $applyDateFilter, $invoiceSearchNotFound] = $this->resolveReportInvoiceFilter(
            $teamId,
            $invoiceSearch
        );

        $grandTotals = null;
        $rows = null;
        try {
            $rows = $this->repository->getReport(
                $dateFrom,
                $dateTo,
                $cities,
                $salesmanIds,
                $storage !== '' ? $storage : null,
                $deliveryStatus !== '' ? $deliveryStatus : null,
                $invoiceIds,
                $page,
                $perPage,
                $applyDateFilter
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
                $this->attachTeamAssignmentToRow($row, $assignmentMap);
            }
            $assignmentTeamOptions = $this->buildAssignmentTeamOptions(
                array_merge($teamFilterOptions, $this->teamsFromAssignmentMap($assignmentMap)),
                $teamsForSetupDate
            );
            $grandTotals = $this->repository->getReportTotals(
                $dateFrom,
                $dateTo,
                $cities,
                $salesmanIds,
                $storage !== '' ? $storage : null,
                $deliveryStatus !== '' ? $deliveryStatus : null,
                $invoiceIds,
                $applyDateFilter
            );
        } catch (Throwable $e) {
            Log::error('Deliveries report failed.', ['message' => $e->getMessage()]);

            return view('reports.deliveries.index', [
                'rows' => null,
                'grandTotals' => null,
                'filters' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'per_page' => $perPage,
                    'cities' => $cities,
                    'salesman_ids' => $salesmanIds,
                    'storage' => $storage,
                    'delivery_status' => $deliveryStatus,
                    'team_id' => $teamId > 0 ? $teamId : '',
                    'tab' => $activeTab,
                    'team_date' => $teamDate,
                    'invoice_search' => $invoiceSearch,
                    'include_amount' => $includeAmount,
                    'include_weight' => $includeWeight,
                ],
                'invoiceSearchNotFound' => $invoiceSearchNotFound,
                'storageOptions' => $storageOptions,
                'cityOptions' => $cityOptions,
                'salesmanOptions' => $salesmanOptions,
                'deliveriesAccess' => $deliveriesAccess,
                'drivers' => $drivers,
                'companions' => $companions,
                'teamsForSetupDate' => $teamsForSetupDate,
                'teamsByDate' => $teamsByDate,
                'teamFilterOptions' => $teamFilterOptions,
                'assignmentTeamOptions' => $assignmentTeamOptions,
                'batchResult' => $batchResult,
                'errorMessage' => 'Unable to load deliveries report. Check logs and try again.',
                'navQuery' => $this->buildNavQuery(
                    $deliveriesAccess,
                    $today,
                    $dateFrom,
                    $dateTo,
                    $perPage,
                    $cities,
                    $salesmanIds,
                    $storage,
                    $deliveryStatus,
                    $teamId,
                    $teamDate,
                    $invoiceSearch,
                    $includeAmount,
                    $includeWeight
                ),
            ]);
        }

        $navQuery = $this->buildNavQuery(
            $deliveriesAccess,
            $today,
            $dateFrom,
            $dateTo,
            $perPage,
            $cities,
            $salesmanIds,
            $storage,
            $deliveryStatus,
            $teamId,
            $teamDate,
            $invoiceSearch,
            $includeAmount,
            $includeWeight
        );

        return view('reports.deliveries.index', [
            'rows' => $rows,
            'grandTotals' => $grandTotals ?? null,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'per_page' => $perPage,
                'cities' => $cities,
                'salesman_ids' => $salesmanIds,
                'storage' => $storage,
                'delivery_status' => $deliveryStatus,
                'team_id' => $teamId > 0 ? $teamId : '',
                'tab' => $activeTab,
                'team_date' => $teamDate,
                'invoice_search' => $invoiceSearch,
                'include_amount' => $includeAmount,
                'include_weight' => $includeWeight,
            ],
            'invoiceSearchNotFound' => $invoiceSearchNotFound,
            'storageOptions' => $storageOptions,
            'cityOptions' => $cityOptions,
            'salesmanOptions' => $salesmanOptions,
            'deliveriesAccess' => $deliveriesAccess,
            'drivers' => $drivers,
            'companions' => $companions,
            'teamsForSetupDate' => $teamsForSetupDate,
            'teamsByDate' => $teamsByDate,
            'teamFilterOptions' => $teamFilterOptions,
            'assignmentTeamOptions' => $assignmentTeamOptions,
            'batchResult' => $batchResult,
            'errorMessage' => null,
            'navQuery' => $navQuery,
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
        $invoiceSearch = trim((string) ($input['invoice_search'] ?? ''));
        $includeAmount = (bool) ($input['include_amount'] ?? false);
        $includeWeight = (bool) ($input['include_weight'] ?? false);
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $salesmanIds = $this->repository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );
        [$invoiceIds, $applyDateFilter] = $this->resolveReportInvoiceFilter($teamId, $invoiceSearch);

        try {
            $rows = $this->repository->exportRows(
                $dateFrom,
                $dateTo,
                $cities,
                $salesmanIds,
                $storage !== '' ? $storage : null,
                $deliveryStatus !== '' ? $deliveryStatus : null,
                $invoiceIds,
                $applyDateFilter
            );
        } catch (Throwable $e) {
            Log::error('Deliveries PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.deliveries.index', $this->redirectQuery($request)))
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
            ...ReportPdfBranding::viewData(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('deliveries-'.$dateFrom.'-'.$dateTo.'.pdf');
    }

    public function exportItemsPdf(DeliveriesReportRequest $request): Response|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $storage = trim((string) ($input['storage'] ?? ''));
        $deliveryStatus = trim((string) ($input['delivery_status'] ?? ''));
        $teamId = (int) ($input['team_id'] ?? 0);
        $invoiceSearch = trim((string) ($input['invoice_search'] ?? ''));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $salesmanIds = $this->repository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );
        [$invoiceIds, $applyDateFilter] = $this->resolveReportInvoiceFilter($teamId, $invoiceSearch);

        try {
            $rows = $this->repository->exportItemRows(
                $dateFrom,
                $dateTo,
                $cities,
                $salesmanIds,
                $storage !== '' ? $storage : null,
                $deliveryStatus !== '' ? $deliveryStatus : null,
                $invoiceIds,
                $applyDateFilter
            );
            $rows = $this->assemblyPriority->sortRows($rows, 'category_name', 'item_name');
        } catch (Throwable $e) {
            Log::error('Deliveries item PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.deliveries.index', $this->redirectQuery($request)))
                ->with('error', 'Could not export item PDF.');
        }

        $totalQty = 0.0;
        $totalWeight = 0.0;
        foreach ($rows as $row) {
            $totalQty += (float) ($row->quantity ?? 0);
            $totalWeight += (float) ($row->weight_total ?? 0);
        }

        $pdf = Pdf::loadView('reports.deliveries.items-pdf', [
            'rows' => $rows,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'cities' => $cities,
            'storage' => $storage,
            'deliveryStatus' => $deliveryStatus,
            'teamId' => $teamId > 0 ? $teamId : null,
            'totalQty' => $totalQty,
            'totalWeight' => $totalWeight,
            ...ReportPdfBranding::viewData(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('deliveries-items-'.$dateFrom.'-'.$dateTo.'.pdf');
    }

    public function exportCsv(DeliveriesReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $storage = trim((string) ($input['storage'] ?? ''));
        $deliveryStatus = trim((string) ($input['delivery_status'] ?? ''));
        $teamId = (int) ($input['team_id'] ?? 0);
        $invoiceSearch = trim((string) ($input['invoice_search'] ?? ''));
        $includeAmount = (bool) ($input['include_amount'] ?? false);
        $includeWeight = (bool) ($input['include_weight'] ?? false);
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $salesmanIds = $this->repository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );
        [$invoiceIds, $applyDateFilter] = $this->resolveReportInvoiceFilter($teamId, $invoiceSearch);

        try {
            $rows = $this->repository->exportRows(
                $dateFrom,
                $dateTo,
                $cities,
                $salesmanIds,
                $storage !== '' ? $storage : null,
                $deliveryStatus !== '' ? $deliveryStatus : null,
                $invoiceIds,
                $applyDateFilter
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
                ->to(route('reports.deliveries.index', $this->redirectQuery($request)))
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

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
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

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
            'tab' => 'setup',
        ]))->with('status', 'Companion saved.');
    }

    public function updateDriver(Request $request, int $person): RedirectResponse
    {
        $validated = $request->validate([
            'driver_name' => ['required', 'string', 'max:200'],
            'car_number' => ['nullable', 'string', 'max:100'],
            'car_model' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $this->teams->updateDriver(
                $person,
                trim((string) $validated['driver_name']),
                trim((string) ($validated['car_number'] ?? '')),
                trim((string) ($validated['car_model'] ?? ''))
            );
        } catch (Throwable $e) {
            return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
                'tab' => 'setup',
            ]))->with('error', $e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
            'tab' => 'setup',
        ]))->with('status', 'Driver updated.');
    }

    public function deleteDriver(Request $request, int $person): RedirectResponse
    {
        try {
            $this->teams->deleteDriver($person);
        } catch (Throwable $e) {
            return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
                'tab' => 'setup',
            ]))->with('error', $e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
            'tab' => 'setup',
        ]))->with('status', 'Driver deleted. Related daily teams and invoice assignments were removed.');
    }

    public function updateCompanion(Request $request, int $person): RedirectResponse
    {
        $validated = $request->validate([
            'companion_name' => ['required', 'string', 'max:200'],
        ]);

        try {
            $this->teams->updateCompanion($person, trim((string) $validated['companion_name']));
        } catch (Throwable $e) {
            return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
                'tab' => 'setup',
            ]))->with('error', $e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
            'tab' => 'setup',
        ]))->with('status', 'Companion updated.');
    }

    public function deleteCompanion(Request $request, int $person): RedirectResponse
    {
        try {
            $this->teams->deleteCompanion($person);
        } catch (Throwable $e) {
            return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
                'tab' => 'setup',
            ]))->with('error', $e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
            'tab' => 'setup',
        ]))->with('status', 'Companion deleted. Related daily teams and invoice assignments were removed.');
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

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
            'tab' => 'daily-teams',
            'team_date' => (string) $validated['team_date'],
        ]))->with('status', 'Daily team saved.');
    }

    public function deleteDailyTeam(Request $request, int $team): RedirectResponse
    {
        try {
            $this->teams->deleteDailyTeam($team);
        } catch (Throwable $e) {
            return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
                'tab' => 'daily-teams',
                'team_date' => $request->query('team_date'),
            ]))->with('error', $e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
            'tab' => 'daily-teams',
            'team_date' => $request->query('team_date'),
        ]))->with('status', 'Daily team deleted.');
    }

    public function updateDeliveryStatus(Request $request): RedirectResponse
    {
        if (! ReportAuthSession::deliveriesAccess()->canEditStatus) {
            return redirect()
                ->route('reports.deliveries.index', $this->redirectQuery($request, ['tab' => 'report']))
                ->with('error', 'You do not have permission to change delivery status.');
        }

        $validated = $request->validate([
            'invoice_id' => ['required', 'string', 'max:100'],
            'current_status' => ['required', 'string', 'in:delivered,not_delivered'],
        ]);

        $currentStatus = (string) $validated['current_status'];
        $nextStatus = $currentStatus === 'delivered' ? 'not delivered' : 'delivered';

        try {
            $updated = $this->repository->updateDeliveryStatus(
                trim((string) $validated['invoice_id']),
                $currentStatus
            );
        } catch (Throwable $e) {
            Log::error('Deliveries status update failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.deliveries.index', $this->redirectQuery($request, ['tab' => 'report']))
                ->with('error', 'Could not update delivery status.');
        }

        if ($updated < 1) {
            return redirect()
                ->route('reports.deliveries.index', $this->redirectQuery($request, ['tab' => 'report']))
                ->with('error', 'No matching delivery rows were updated.');
        }

        return redirect()
            ->route('reports.deliveries.index', $this->redirectQuery($request, ['tab' => 'report']))
            ->with('status', 'Delivery status changed to '.$nextStatus.'.');
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

        return redirect()->route('reports.deliveries.index', $this->redirectQuery($request, ['tab' => 'report']))->with('status', 'Invoice team assignment saved.');
    }

    public function batchAssignFromPdf(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
            'batch_pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'],
        ]);

        try {
            $numbers = $this->pdfExtractor->extractInvoiceNumbersFromUpload(
                $request->file('batch_pdf')
            );
            $matches = $this->repository->findInvoicesByInvoiceNumbersForBatch($numbers);

            $needles = [];
            foreach ($numbers as $number) {
                $key = $this->normalizeBatchInvoiceNumberKey((string) $number);
                if ($key !== '') {
                    $needles[$key] = true;
                }
            }

            $matchedKeys = [];
            foreach ($matches as $row) {
                $key = $this->normalizeBatchInvoiceNumberKey((string) ($row->invoice_no ?? ''));
                if ($key !== '') {
                    $matchedKeys[$key] = true;
                }
            }

            $unmatchedKeys = array_diff_key($needles, $matchedKeys);
            if ($unmatchedKeys !== []) {
                $assignedIds = $this->teams->listAllAssignedInvoiceIds();
                if ($assignedIds !== []) {
                    foreach ($this->repository->findInvoicesByInvoiceIds($assignedIds) as $row) {
                        $key = $this->normalizeBatchInvoiceNumberKey((string) ($row->invoice_no ?? ''));
                        if ($key !== '' && isset($unmatchedKeys[$key])) {
                            $matches[] = $row;
                            unset($unmatchedKeys[$key]);
                            if ($unmatchedKeys === []) {
                                break;
                            }
                        }
                    }
                }
            }

            $byInvoiceId = [];
            foreach ($matches as $row) {
                $invoiceId = trim((string) ($row->invoice_id ?? ''));
                if ($invoiceId !== '') {
                    $byInvoiceId[$invoiceId] = $row;
                }
            }

            $existingAssignments = $this->teams->assignmentsByInvoiceIds(array_keys($byInvoiceId));

            $assignedCount = 0;
            $reassignedCount = 0;
            $matchedNumbers = [];
            foreach ($byInvoiceId as $row) {
                $invoiceId = trim((string) ($row->invoice_id ?? ''));
                if ($invoiceId === '') {
                    continue;
                }

                $previousTeamId = isset($existingAssignments[$invoiceId])
                    ? (int) ($existingAssignments[$invoiceId]->team_id ?? 0)
                    : 0;
                $targetTeamId = (int) $validated['team_id'];

                $this->teams->assignInvoiceTeam(
                    $invoiceId,
                    (string) ($row->document_date ?? Carbon::now()->toDateString()),
                    $targetTeamId
                );
                $assignedCount++;
                if ($previousTeamId > 0 && $previousTeamId !== $targetTeamId) {
                    $reassignedCount++;
                }

                $invoiceNo = trim((string) ($row->invoice_no ?? ''));
                if ($invoiceNo !== '') {
                    $matchedNumbers[] = $invoiceNo;
                }
            }

            $unmatchedCount = count(array_diff_key($needles, array_flip(array_map(
                fn (string $invoiceNo): string => $this->normalizeBatchInvoiceNumberKey($invoiceNo),
                array_values(array_unique(array_filter($matchedNumbers)))
            ))));
        } catch (Throwable $e) {
            return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
                'tab' => 'batch-assignment',
            ]))->with('error', 'Batch assignment failed: '.$e->getMessage());
        }

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
            'tab' => 'batch-assignment',
        ]))->with('batch_result', [
            'team_id' => (int) $validated['team_id'],
            'extracted_count' => count($numbers),
            'matched_count' => count($byInvoiceId),
            'assigned_count' => $assignedCount,
            'reassigned_count' => $reassignedCount,
            'unmatched_count' => $unmatchedCount,
        ])->with('status', 'Batch assignment completed.');
    }

    private function normalizeBatchInvoiceNumberKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            $trimmed = ltrim($value, '0');

            return $trimmed === '' ? '0' : $trimmed;
        }

        return mb_strtolower($value);
    }

    public function clearTeamAssignments(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $removedCount = $this->teams->clearTeamAssignments((int) $validated['team_id']);
        } catch (Throwable $e) {
            Log::error('deliveries.clear_team_assignments_failed', ['message' => $e->getMessage()]);

            return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
                'tab' => 'batch-assignment',
            ]))->with('error', 'Could not clear team assignments.');
        }

        $message = $removedCount > 0
            ? 'Removed '.$removedCount.' invoice assignment(s) for the selected team.'
            : 'No invoice assignments were found for the selected team.';

        return redirect()->route('reports.deliveries.index', array_merge($this->redirectQuery($request), [
            'tab' => 'batch-assignment',
        ]))->with('status', $message);
    }

    /**
     * @return array{0: list<string>|null, 1: bool, 2: bool}
     */
    private function resolveReportInvoiceFilter(int $teamId, string $invoiceSearch): array
    {
        $invoiceSearch = trim($invoiceSearch);
        if ($invoiceSearch !== '') {
            try {
                $ids = $this->repository->resolveInvoiceIdsByNumberSearch($invoiceSearch);
            } catch (Throwable $e) {
                Log::warning('Deliveries invoice search failed.', ['message' => $e->getMessage()]);

                return [[], false, true];
            }

            return [$ids, false, $ids === []];
        }

        if ($teamId > 0) {
            [$invoiceIds, $applyDateFilter] = $this->resolveTeamInvoiceFilter($teamId);

            return [$invoiceIds, $applyDateFilter, false];
        }

        return [null, true, false];
    }

    /**
     * @return array{0: list<string>|null, 1: bool}
     */
    private function resolveTeamInvoiceFilter(int $teamId): array
    {
        try {
            return [$this->teams->invoiceIdsForTeam($teamId), false];
        } catch (Throwable $e) {
            Log::warning('Deliveries team filter failed.', ['message' => $e->getMessage()]);

            return [[], false];
        }
    }

    /**
     * @param  array<string, object>  $assignmentMap
     */
    private function attachTeamAssignmentToRow(object $row, array $assignmentMap): void
    {
        $invoiceId = trim((string) ($row->invoice_id ?? ''));
        $assigned = $assignmentMap[$invoiceId] ?? null;
        if ($assigned === null) {
            $row->team_id = null;
            $row->team_name = '';
            $row->assigned_team_date = '';
            $row->assigned_team = null;

            return;
        }

        $row->team_id = (int) ($assigned->team_id ?? 0);
        $row->team_name = $this->teams->teamLabel($assigned);
        $row->assigned_team_date = trim((string) ($assigned->team_date ?? ''));
        $row->assigned_team = (object) [
            'id' => (int) ($assigned->team_id ?? 0),
            'team_date' => trim((string) ($assigned->team_date ?? '')),
            'driver_name' => trim((string) ($assigned->driver_name ?? '')),
            'companion_name' => trim((string) ($assigned->companion_name ?? '')),
        ];
    }

    /**
     * @param  list<object>  $teamsInRange
     * @param  list<object>  $teamsForSetupDate
     * @return list<object>
     */
    private function buildAssignmentTeamOptions(array $teamsInRange, array $teamsForSetupDate): array
    {
        $byId = [];
        foreach (array_merge($teamsInRange, $teamsForSetupDate) as $team) {
            $id = (int) ($team->id ?? 0);
            if ($id > 0) {
                $byId[$id] = $team;
            }
        }

        $teams = array_values($byId);
        usort($teams, static function (object $a, object $b): int {
            $dateCmp = strcmp((string) ($b->team_date ?? ''), (string) ($a->team_date ?? ''));
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return strcmp((string) ($a->driver_name ?? ''), (string) ($b->driver_name ?? ''));
        });

        return $teams;
    }

    /**
     * @param  array<string, object>  $assignmentMap
     * @return list<object>
     */
    private function teamsFromAssignmentMap(array $assignmentMap): array
    {
        $teams = [];
        foreach ($assignmentMap as $assigned) {
            $id = (int) ($assigned->team_id ?? 0);
            if ($id <= 0 || isset($teams[$id])) {
                continue;
            }

            $teams[$id] = (object) [
                'id' => $id,
                'team_date' => trim((string) ($assigned->team_date ?? '')),
                'driver_name' => trim((string) ($assigned->driver_name ?? '')),
                'companion_name' => trim((string) ($assigned->companion_name ?? '')),
            ];
        }

        return array_values($teams);
    }

    /**
     * @param  list<string>  $cities
     * @param  list<int>  $salesmanIds
     * @return array<string, mixed>
     */
    private function buildNavQuery(
        DeliveriesReportAccess $access,
        string $today,
        string $dateFrom,
        string $dateTo,
        int $perPage,
        array $cities,
        array $salesmanIds,
        string $storage,
        string $deliveryStatus,
        int $teamId,
        string $teamDate,
        string $invoiceSearch,
        bool $includeAmount,
        bool $includeWeight,
    ): array {
        return $access->snapshotForRedirect([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'per_page' => $perPage,
            'cities' => $cities,
            'salesman_ids' => $salesmanIds,
            'storage' => $storage,
            'delivery_status' => $deliveryStatus,
            'team_id' => $teamId > 0 ? $teamId : '',
            'team_date' => $teamDate,
            'invoice_search' => $invoiceSearch,
            'include_amount' => $includeAmount,
            'include_weight' => $includeWeight,
        ], $today);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function redirectQuery(Request $request, array $extra = []): array
    {
        $today = Carbon::now()->toDateString();
        $merged = array_merge(
            $request->query(),
            $request->only(DeliveriesReportAccess::filterKeys())
        );

        $snapshot = ReportAuthSession::deliveriesAccess()->snapshotForRedirect($merged, $today);

        return array_merge($snapshot, $extra);
    }
}
