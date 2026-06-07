<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SalesReportExport;
use App\Http\Requests\SalesClientItemsRequest;
use App\Http\Requests\SalesReportRequest;
use App\Repositories\SalesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Services\ReportAssemblyPriorityService;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SalesReportController extends Controller
{
    private const EXPORT_ROW_CAP = 10000;

    public function __construct(
        private readonly SalesReportRepository $repository,
        private readonly ReportAssemblyPriorityService $assemblyPriority,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly CitiesGovernorateSqliteService $governorates,
    ) {}

    public function index(SalesReportRequest $request): View
    {
        $today = Carbon::now()->toDateString();
        $defaults = [
            'date_from' => $today,
            'date_to' => $today,
            'group_by_client' => true,
            'per_page' => 250,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'breakdown_items' => false,
            'q' => '',
            'customer_account_ids' => [],
            'saved_governorate_id' => 0,
            'salesman_ids' => [],
            'storage' => '',
            'include_quantity' => true,
            'include_amount' => true,
            'include_weight' => true,
        ];

        $input = array_merge($defaults, $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $groupByClient = (bool) ($input['group_by_client'] ?? false);
        $perPage = (int) ($input['per_page'] ?? 250);
        $page = (int) ($input['page'] ?? 1);
        $breakdown = (bool) ($input['breakdown'] ?? false);
        $breakdownByClient = (bool) ($input['breakdown_by_client'] ?? false);
        $breakdownItems = (bool) ($input['breakdown_items'] ?? false);
        $q = trim((string) ($input['q'] ?? ''));
        $customerAccountIds = $this->repository->normalizeCustomerAccountIds(
            is_array($input['customer_account_ids'] ?? null) ? $input['customer_account_ids'] : []
        );

        $geo = $this->resolveGeoFilters($input);
        $citiesFilter = $geo['cities'];
        $salesmanIds = $geo['salesman_ids'];
        $storage = trim((string) ($input['storage'] ?? ''));
        $storageFilter = $storage !== '' ? $storage : null;

        $filters = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'group_by_client' => $groupByClient,
            'per_page' => $perPage,
            'breakdown' => $breakdown,
            'breakdown_by_client' => $breakdownByClient,
            'breakdown_items' => $breakdownItems,
            'q' => $q,
            'customer_account_ids' => $customerAccountIds,
            'saved_governorate_id' => $geo['saved_governorate_id'],
            'salesman_ids' => $salesmanIds,
            'storage' => $storage,
            'include_quantity' => (bool) ($input['include_quantity'] ?? true),
            'include_amount' => (bool) ($input['include_amount'] ?? true),
            'include_weight' => (bool) ($input['include_weight'] ?? true),
        ];

        $customerOptions = $this->repository->getCustomerAccountOptions();
        [$savedGovernorates, $salesmanOptions] = $this->loadFilterOptions();
        $storageOptions = $this->repository->getStorageOptions();
        $categorySearch = ($breakdown || $breakdownByClient) && $q !== '' ? $q : null;
        $grandTotals = $this->loadMetricGrandTotals(
            $dateFrom,
            $dateTo,
            $customerAccountIds,
            $citiesFilter,
            $salesmanIds,
            $storageFilter,
            $categorySearch
        );

        try {
            if ($breakdownByClient) {
                $result = $this->repository->getChickenCategoryBreakdownByClient(
                    $dateFrom,
                    $dateTo,
                    $q !== '' ? $q : null,
                    $page,
                    $perPage,
                    $customerAccountIds,
                    $citiesFilter,
                    $salesmanIds,
                    $storageFilter
                );
                $result->setCollection(collect(
                    $this->assemblyPriority->sortRows($result->items(), 'chicken_category', 'chicken_category')
                ));

                return view('reports.sales.index', [
                    'mode' => 'by_category_by_client',
                    'rows' => $result,
                    'totals' => null,
                    'filters' => $filters,
                    'customerOptions' => $customerOptions,
                    'savedGovernorates' => $savedGovernorates,
                    'salesmanOptions' => $salesmanOptions,
                    'storageOptions' => $storageOptions,
                    'governorateLabel' => $geo['governorate_label'],
                    'grandTotals' => $grandTotals,
                    'errorMessage' => null,
                ]);
            }

            if ($breakdown) {
                if ($breakdownItems) {
                    $result = $this->repository->getChickenCategoryItemBreakdown(
                        $dateFrom,
                        $dateTo,
                        $q !== '' ? $q : null,
                        $page,
                        $perPage,
                        $customerAccountIds,
                        $citiesFilter,
                        $salesmanIds,
                        $storageFilter
                    );
                    $result->setCollection(collect(
                        $this->assemblyPriority->sortRows($result->items(), 'chicken_category', 'item_name')
                    ));

                    $categoryTotals = $this->loadSalesCategoryTotals(
                        $dateFrom,
                        $dateTo,
                        $q !== '' ? $q : null,
                        $customerAccountIds,
                        $citiesFilter,
                        $salesmanIds,
                        $storageFilter
                    );

                    return view('reports.sales.index', [
                        'mode' => 'by_category_items',
                        'rows' => $result,
                        'totals' => null,
                        'filters' => $filters,
                        'customerOptions' => $customerOptions,
                        'savedGovernorates' => $savedGovernorates,
                        'salesmanOptions' => $salesmanOptions,
                        'storageOptions' => $storageOptions,
                        'governorateLabel' => $geo['governorate_label'],
                        'grandTotals' => $grandTotals,
                        'categoryTotalsList' => $categoryTotals['list'],
                        'categoryTotalsMap' => $categoryTotals['map'],
                        'errorMessage' => null,
                    ]);
                }

                $result = $this->repository->getChickenCategoryBreakdown(
                    $dateFrom,
                    $dateTo,
                    $q !== '' ? $q : null,
                    $page,
                    $perPage,
                    $customerAccountIds,
                    $citiesFilter,
                    $salesmanIds,
                    $storageFilter
                );
                $result->setCollection(collect(
                    $this->assemblyPriority->sortRows($result->items(), 'chicken_category', 'chicken_category')
                ));

                return view('reports.sales.index', [
                    'mode' => 'by_category',
                    'rows' => $result,
                    'totals' => null,
                    'filters' => $filters,
                    'customerOptions' => $customerOptions,
                    'savedGovernorates' => $savedGovernorates,
                    'salesmanOptions' => $salesmanOptions,
                    'storageOptions' => $storageOptions,
                    'governorateLabel' => $geo['governorate_label'],
                    'grandTotals' => $grandTotals,
                    'errorMessage' => null,
                ]);
            }

            $result = $this->repository->getReport(
                $dateFrom,
                $dateTo,
                $groupByClient,
                $page,
                $perPage,
                $customerAccountIds,
                $citiesFilter,
                $salesmanIds,
                $storageFilter
            );

            if ($groupByClient && $result instanceof LengthAwarePaginator) {
                return view('reports.sales.index', [
                    'mode' => 'by_client',
                    'rows' => $result,
                    'totals' => null,
                    'filters' => $filters,
                    'customerOptions' => $customerOptions,
                    'savedGovernorates' => $savedGovernorates,
                    'salesmanOptions' => $salesmanOptions,
                    'storageOptions' => $storageOptions,
                    'governorateLabel' => $geo['governorate_label'],
                    'grandTotals' => $grandTotals,
                    'errorMessage' => null,
                ]);
            }

            $totals = is_array($result) && isset($result[0]) ? $result[0] : null;

            return view('reports.sales.index', [
                'mode' => 'totals',
                'rows' => null,
                'totals' => $totals,
                'grandTotals' => $grandTotals ?? $totals,
                'filters' => $filters,
                'customerOptions' => $customerOptions,
                'savedGovernorates' => $savedGovernorates,
                'salesmanOptions' => $salesmanOptions,
                'storageOptions' => $storageOptions,
                'governorateLabel' => $geo['governorate_label'],
                'errorMessage' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Sales report failed.', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'group_by_client' => $groupByClient,
                'breakdown' => $breakdown,
                'breakdown_by_client' => $breakdownByClient,
                'breakdown_items' => $breakdownItems,
                'message' => $exception->getMessage(),
            ]);

            return view('reports.sales.index', [
                'mode' => $breakdownByClient
                    ? 'by_category_by_client'
                    : ($breakdown
                        ? ($breakdownItems ? 'by_category_items' : 'by_category')
                        : ($groupByClient ? 'by_client' : 'totals')),
                'rows' => null,
                'totals' => null,
                'filters' => $filters,
                'customerOptions' => $customerOptions,
                'savedGovernorates' => $savedGovernorates,
                'salesmanOptions' => $salesmanOptions,
                'storageOptions' => $storageOptions,
                'governorateLabel' => $geo['governorate_label'],
                'grandTotals' => $grandTotals,
                'errorMessage' => 'Unable to load sales report. Check logs and try again.',
            ]);
        }
    }

    /**
     * @param  list<string>  $customerAccountIds
     * @param  list<string>  $citiesFilter
     * @param  list<string>  $salesmanIds
     */
    private function loadMetricGrandTotals(
        string $dateFrom,
        string $dateTo,
        array $customerAccountIds,
        array $citiesFilter,
        array $salesmanIds,
        ?string $storage,
        ?string $categorySearch
    ): ?\stdClass {
        try {
            return $this->repository->getMetricGrandTotals(
                $dateFrom,
                $dateTo,
                $customerAccountIds,
                $citiesFilter,
                $salesmanIds,
                $storage,
                $categorySearch
            );
        } catch (Throwable $e) {
            Log::warning('sales.grand_totals_unavailable', ['message' => $e->getMessage()]);

            return null;
        }
    }

    public function clientItems(SalesClientItemsRequest $request): JsonResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $clientAccountId = (string) $input['client_account_id'];

        try {
            $rows = $this->repository->getClientItemBreakdown($dateFrom, $dateTo, $clientAccountId);
            $rows = $this->assemblyPriority->sortRows($rows, 'item_category', 'item_name');
        } catch (Throwable $e) {
            Log::error('Sales client item breakdown failed.', [
                'client_account_id' => $clientAccountId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Could not load item breakdown.',
                'rows' => [],
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'rows' => array_map(static function (object $row): array {
                return [
                    'item_category' => (string) ($row->item_category ?? ''),
                    'item_name' => (string) ($row->item_name ?? ''),
                    'units_sold' => (float) ($row->units_sold ?? 0),
                    'amount' => (float) ($row->amount ?? 0),
                    'weight_total' => (float) ($row->weight_total ?? 0),
                ];
            }, $rows),
        ]);
    }

    public function exportPdf(SalesReportRequest $request): Response|RedirectResponse
    {
        $input = array_merge([
            'group_by_client' => true,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'breakdown_items' => false,
            'q' => '',
            'customer_account_ids' => [],
            'saved_governorate_id' => 0,
            'salesman_ids' => [],
            'storage' => '',
            'include_quantity' => true,
            'include_amount' => true,
            'include_weight' => true,
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $customerAccountIds = $this->repository->normalizeCustomerAccountIds(
            is_array($input['customer_account_ids'] ?? null) ? $input['customer_account_ids'] : []
        );
        $geo = $this->resolveGeoFilters($input);
        $storage = trim((string) ($input['storage'] ?? ''));
        $storageFilter = $storage !== '' ? $storage : null;

        $exportMode = $this->resolveExportMode($input);

        try {
            $rows = $this->fetchExportRows(
                $exportMode,
                $dateFrom,
                $dateTo,
                $q,
                $customerAccountIds,
                $geo['cities'],
                $geo['salesman_ids'],
                $storageFilter
            );
        } catch (Throwable $e) {
            Log::error('Sales PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales.index', $request->query()))
                ->with('error', 'Could not export PDF. Check logs and try a narrower date range or fewer filters.');
        }

        $categoryTotals = ['list' => [], 'map' => []];
        $categorySearch = in_array($exportMode, ['by_category', 'by_category_items', 'by_category_by_client'], true) && $q !== ''
            ? $q
            : null;
        $pdfGrandTotals = $this->loadMetricGrandTotals(
            $dateFrom,
            $dateTo,
            $customerAccountIds,
            $geo['cities'],
            $geo['salesman_ids'],
            $storageFilter,
            $categorySearch
        );
        if ($exportMode === 'by_category_items') {
            $categoryTotals = $this->loadSalesCategoryTotals(
                $dateFrom,
                $dateTo,
                $q !== '' ? $q : null,
                $customerAccountIds,
                $geo['cities'],
                $geo['salesman_ids'],
                $storageFilter
            );
        }

        $pdf = Pdf::loadView('reports.sales.export-pdf', [
            'mode' => $exportMode,
            'rows' => $rows,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'q' => $q,
            'modeLabel' => $this->exportModeLabel($exportMode),
            'customerLabel' => $this->customerFilterLabel($customerAccountIds),
            'governorateLabel' => $geo['governorate_label'],
            'salesmanLabel' => $this->salesmanFilterLabel($geo['salesman_ids']),
            'storageLabel' => $storage,
            'includeQuantity' => (bool) ($input['include_quantity'] ?? true),
            'includeAmount' => (bool) ($input['include_amount'] ?? true),
            'includeWeight' => (bool) ($input['include_weight'] ?? true),
            'categoryTotalsList' => $categoryTotals['list'],
            'grandTotals' => $pdfGrandTotals,
            ...ReportPdfBranding::viewData(),
        ])->setPaper('a4', $exportMode === 'by_category_by_client' ? 'landscape' : 'portrait');

        $filename = 'sales-'.$dateFrom.'-'.$dateTo.'.pdf';

        return $pdf->download($filename);
    }

    public function exportCsv(SalesReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = array_merge([
            'group_by_client' => true,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'breakdown_items' => false,
            'q' => '',
            'customer_account_ids' => [],
            'saved_governorate_id' => 0,
            'salesman_ids' => [],
            'storage' => '',
            'include_quantity' => true,
            'include_amount' => true,
            'include_weight' => true,
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $customerAccountIds = $this->repository->normalizeCustomerAccountIds(
            is_array($input['customer_account_ids'] ?? null) ? $input['customer_account_ids'] : []
        );
        $geo = $this->resolveGeoFilters($input);
        $storage = trim((string) ($input['storage'] ?? ''));
        $storageFilter = $storage !== '' ? $storage : null;

        $exportMode = $this->resolveExportMode($input);

        try {
            $rows = $this->fetchExportRows(
                $exportMode,
                $dateFrom,
                $dateTo,
                $q,
                $customerAccountIds,
                $geo['cities'],
                $geo['salesman_ids'],
                $storageFilter
            );
        } catch (Throwable $e) {
            Log::error('Sales CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        $filename = 'sales-'.$dateFrom.'-'.$dateTo.'.csv';

        return Excel::download(
            new SalesReportExport(
                $rows,
                $exportMode,
                (bool) ($input['include_quantity'] ?? true),
                (bool) ($input['include_amount'] ?? true),
                (bool) ($input['include_weight'] ?? true)
            ),
            $filename,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     cities: list<string>,
     *     salesman_ids: list<string>,
     *     saved_governorate_id: int,
     *     governorate_label: string
     * }
     */
    private function resolveGeoFilters(array $input): array
    {
        $savedGovernorateId = (int) ($input['saved_governorate_id'] ?? 0);
        $salesmanIds = $this->repository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );

        $governorateCities = [];
        $governorateLabel = '';
        if ($savedGovernorateId > 0) {
            try {
                $selectedGov = $this->governorates->getGovernorateById($savedGovernorateId);
                if ($selectedGov !== null) {
                    $governorateCities = $this->repository->normalizeCities((array) ($selectedGov['members'] ?? []));
                    $governorateLabel = (string) ($selectedGov['name'] ?? '');
                }
            } catch (Throwable $e) {
                Log::warning('sales.governorate_unavailable', ['message' => $e->getMessage()]);
            }
        }

        return [
            'cities' => $governorateCities,
            'salesman_ids' => $salesmanIds,
            'saved_governorate_id' => $savedGovernorateId,
            'governorate_label' => $governorateLabel,
        ];
    }

    /**
     * @return array{0: list<object>, 1: list<array{id: string, name: string}>}
     */
    private function loadFilterOptions(): array
    {
        $savedGovernorates = [];
        $salesmanOptions = [];
        try {
            $savedGovernorates = $this->governorates->listGovernorates();
        } catch (Throwable $e) {
            Log::warning('sales.governorates_list_unavailable', ['message' => $e->getMessage()]);
        }
        try {
            $salesmanOptions = $this->visitsRepository->getSalesmanOptions();
        } catch (Throwable $e) {
            Log::warning('sales.salesmen_unavailable', ['message' => $e->getMessage()]);
        }

        return [$savedGovernorates, $salesmanOptions];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return 'totals'|'by_client'|'by_category'|'by_category_items'|'by_category_by_client'
     */
    private function resolveExportMode(array $input): string
    {
        if (! empty($input['breakdown_by_client'])) {
            return 'by_category_by_client';
        }
        if (! empty($input['breakdown']) && ! empty($input['breakdown_items'])) {
            return 'by_category_items';
        }
        if (! empty($input['breakdown'])) {
            return 'by_category';
        }
        if (! empty($input['group_by_client'])) {
            return 'by_client';
        }

        return 'totals';
    }

    /**
     * @param  list<string>  $customerAccountIds
     * @param  list<string>  $citiesFilter
     * @param  list<string>  $salesmanIds
     * @return list<stdClass>
     */
    private function fetchExportRows(
        string $exportMode,
        string $dateFrom,
        string $dateTo,
        string $q,
        array $customerAccountIds,
        array $citiesFilter,
        array $salesmanIds,
        ?string $storage = null
    ): array {
        $qOrNull = $q !== '' ? $q : null;

        return match ($exportMode) {
            'by_category_by_client' => $this->repository->exportChickenCategoryByClientRows(
                $dateFrom,
                $dateTo,
                $qOrNull,
                $customerAccountIds,
                $citiesFilter,
                $salesmanIds,
                $storage
            ),
            'by_category' => $this->repository->exportChickenCategoryRows(
                $dateFrom,
                $dateTo,
                $qOrNull,
                $customerAccountIds,
                $citiesFilter,
                $salesmanIds,
                $storage
            ),
            'by_category_items' => $this->repository->exportChickenCategoryItemRows(
                $dateFrom,
                $dateTo,
                $qOrNull,
                $customerAccountIds,
                $citiesFilter,
                $salesmanIds,
                $storage
            ),
            'by_client' => $this->repository->exportReportRows(
                $dateFrom,
                $dateTo,
                true,
                $customerAccountIds,
                $citiesFilter,
                $salesmanIds,
                $storage
            ),
            default => $this->repository->exportReportRows(
                $dateFrom,
                $dateTo,
                false,
                $customerAccountIds,
                $citiesFilter,
                $salesmanIds,
                $storage
            ),
        };
    }

    /**
     * @param  list<string>  $customerAccountIds
     */
    private function customerFilterLabel(array $customerAccountIds): string
    {
        if ($customerAccountIds === []) {
            return '';
        }

        $byId = collect($this->repository->getCustomerAccountOptions())->keyBy('id');
        $parts = [];
        foreach ($customerAccountIds as $id) {
            $row = $byId->get($id);
            $parts[] = is_array($row) ? ($row['name'] ?? $id) : $id;
        }

        return implode('; ', $parts);
    }

    /**
     * @param  list<string>  $salesmanIds
     */
    private function salesmanFilterLabel(array $salesmanIds): string
    {
        if ($salesmanIds === []) {
            return '';
        }

        $byId = collect($this->visitsRepository->getSalesmanOptions())->keyBy('id');
        $parts = [];
        foreach ($salesmanIds as $id) {
            $row = $byId->get($id);
            $parts[] = is_array($row) ? ($row['name'] ?? $id) : $id;
        }

        return implode('; ', $parts);
    }

    private function exportModeLabel(string $mode): string
    {
        return match ($mode) {
            'by_category_by_client' => 'Category by client',
            'by_category_items' => 'Category breakdown with items',
            'by_category' => 'Category breakdown',
            'by_client' => 'By client',
            'totals' => 'Period totals',
            default => $mode,
        };
    }

    /**
     * @param  list<string>  $customerAccountIds
     * @param  list<string>  $citiesFilter
     * @param  list<string>  $salesmanIds
     * @return array{
     *     list: list<array{category: string, units_sold: float, amount: float, weight_total: float}>,
     *     map: array<string, array{units_sold: float, amount: float, weight_total: float}>
     * }
     */
    private function loadSalesCategoryTotals(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        array $customerAccountIds,
        array $citiesFilter,
        array $salesmanIds,
        ?string $storage = null
    ): array {
        try {
            $rows = $this->repository->exportChickenCategoryRows(
                $dateFrom,
                $dateTo,
                $searchDescription,
                $customerAccountIds,
                $citiesFilter,
                $salesmanIds,
                $storage
            );
        } catch (Throwable $e) {
            Log::warning('sales.category_totals_unavailable', ['message' => $e->getMessage()]);

            return ['list' => [], 'map' => []];
        }

        return $this->mapSalesCategoryTotals($rows);
    }

    /**
     * @param  list<stdClass>  $rows
     * @return array{
     *     list: list<array{category: string, units_sold: float, amount: float, weight_total: float}>,
     *     map: array<string, array{units_sold: float, amount: float, weight_total: float}>
     * }
     */
    private function mapSalesCategoryTotals(array $rows): array
    {
        $list = [];
        $map = [];
        foreach ($rows as $row) {
            $category = (string) ($row->chicken_category ?? '');
            $entry = [
                'category' => $category,
                'units_sold' => (float) ($row->units_sold ?? 0),
                'amount' => (float) ($row->amount ?? 0),
                'weight_total' => (float) ($row->weight_total ?? 0),
            ];
            $list[] = $entry;
            $map[$category] = [
                'units_sold' => $entry['units_sold'],
                'amount' => $entry['amount'],
                'weight_total' => $entry['weight_total'],
            ];
        }

        return ['list' => $list, 'map' => $map];
    }
}
