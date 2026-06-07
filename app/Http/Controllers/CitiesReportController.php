<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SalesReportExport;
use App\Http\Requests\CitiesReportRequest;
use App\Repositories\CitiesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Services\ReportAssemblyPriorityService;
use App\Support\SvgSalesTimeSeriesChart;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CitiesReportController extends Controller
{
    private const EXPORT_ROW_CAP = 10000;

    public function __construct(
        private readonly CitiesReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly ReportAssemblyPriorityService $assemblyPriority
    ) {}

    public function index(CitiesReportRequest $request): View|RedirectResponse
    {
        $govService = app(CitiesGovernorateSqliteService::class);
        $today = Carbon::now()->toDateString();
        $defaults = [
            'date_from' => $today,
            'date_to' => $today,
            'group_by_client' => true,
            'per_page' => 250,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'q' => '',
            'cities' => [],
            'salesman_ids' => [],
            'panel' => 'table',
            'city_page' => 'overview',
            'governorate_city' => '',
            'governorate_members' => [],
            'pie_category' => '',
            'saved_governorate_id' => null,
            'edit_governorate_id' => null,
        ];

        $input = array_merge($defaults, $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $groupByClient = (bool) ($input['group_by_client'] ?? false);
        $perPage = (int) ($input['per_page'] ?? 250);
        $page = (int) ($input['page'] ?? 1);
        $breakdown = (bool) ($input['breakdown'] ?? false);
        $breakdownByClient = (bool) ($input['breakdown_by_client'] ?? false);
        $q = trim((string) ($input['q'] ?? ''));
        $panel = (string) ($input['panel'] ?? 'table');
        $cityPage = (string) ($input['city_page'] ?? 'overview');
        $governorateCity = trim((string) ($input['governorate_city'] ?? ''));
        $governorateMembers = $this->repository->normalizeCities(
            is_array($input['governorate_members'] ?? null) ? $input['governorate_members'] : []
        );
        $pieCategory = trim((string) ($input['pie_category'] ?? ''));
        $excludeCategory = trim((string) ($input['exclude_category'] ?? ''));
        $savedGovernorateId = (int) ($input['saved_governorate_id'] ?? 0);
        $editGovernorateId = (int) ($input['edit_governorate_id'] ?? 0);
        if ($editGovernorateId > 0) {
            return redirect()->route('reports.governorates.index', ['edit' => $editGovernorateId]);
        }
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $salesmanIds = $this->repository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );

        $savedGovernorates = [];
        $governorateStorageError = null;
        try {
            $savedGovernorates = $govService->listGovernorates();
            if ($savedGovernorateId > 0) {
                $selectedGovernorate = $govService->getGovernorateById($savedGovernorateId);
                if ($selectedGovernorate !== null) {
                    $governorateCity = $selectedGovernorate['governorate_city'];
                    $governorateMembers = $this->repository->normalizeCities($selectedGovernorate['members']);
                    if (in_array($cityPage, ['pie-charts', 'salesman-pie'], true)) {
                        $cities = $this->repository->normalizeCities($selectedGovernorate['members']);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('cities.governorate_storage_unavailable', ['message' => $e->getMessage()]);
            $savedGovernorates = [];
            $governorateStorageError = 'Saved governorates could not be loaded ('.$e->getMessage().'). Check the deliveries SQLite path (DELIVERIES_SQLITE_DATABASE).';
        }

        $filters = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'group_by_client' => $groupByClient,
            'per_page' => $perPage,
            'breakdown' => $breakdown,
            'breakdown_by_client' => $breakdownByClient,
            'q' => $q,
            'cities' => $cities,
            'salesman_ids' => $salesmanIds,
            'panel' => $panel,
            'city_page' => $cityPage,
            'governorate_city' => $governorateCity,
            'governorate_members' => $governorateMembers,
            'pie_category' => $pieCategory,
            'exclude_category' => $excludeCategory,
            'saved_governorate_id' => $savedGovernorateId > 0 ? $savedGovernorateId : '',
        ];

        $cityOptions = $this->cityOptionsForPicker();
        $salesmanOptions = $this->visitsRepository->getSalesmanOptions();
        $cityNames = array_values(array_map(
            static fn (array $c): string => (string) ($c['id'] ?? ''),
            $cityOptions
        ));
        $hasCityColumn = $this->visitsRepository->getAccountCityColumnName() !== null;

        $chartTimeSeries = [];
        if ($panel === 'charts' && $cityPage === 'overview') {
            try {
                $chartTimeSeries = $this->repository->getSalesOverTimeChartSeries($dateFrom, $dateTo, $cities, $salesmanIds);
            } catch (Throwable $e) {
                Log::warning('cities.chart_time_series_failed', ['message' => $e->getMessage()]);
                $chartTimeSeries = [];
            }
        }

        $governorateRows = null;
        $pieSeriesByCity = [];
        $pieSeriesByCategory = [];
        $pieSeriesByItem = [];
        $pieSeriesBySalesman = [];
        $pieCategoryOptions = [];
        if ($cityPage === 'governorate-breakdown' || $cityPage === 'pie-charts' || $cityPage === 'salesman-pie') {
            try {
                $pieCategoryOptions = $this->repository->getItemCategoryOptions($dateFrom, $dateTo, $cities, $salesmanIds);
            } catch (Throwable $e) {
                Log::warning('cities.category_options_failed', ['message' => $e->getMessage()]);
                $pieCategoryOptions = [];
            }
        }

        $grandTotals = null;
        if ($panel === 'table' && $cityPage === 'overview') {
            $categorySearch = ($breakdown || $breakdownByClient) && $q !== '' ? $q : null;
            try {
                $grandTotals = $this->repository->getMetricGrandTotals(
                    $dateFrom,
                    $dateTo,
                    $cities,
                    $salesmanIds,
                    $categorySearch
                );
            } catch (Throwable $e) {
                Log::warning('cities.grand_totals_unavailable', ['message' => $e->getMessage()]);
            }
        }

        try {
            if ($cityPage === 'governorate-breakdown') {
                $governorateRows = $this->repository->getGovernorateCategoryBreakdown(
                    $dateFrom,
                    $dateTo,
                    $governorateCity,
                    $governorateMembers,
                    $excludeCategory !== '' ? $excludeCategory : null,
                    $page,
                    $perPage,
                    $salesmanIds
                );
                $governorateRows->setCollection(collect(
                    $this->assemblyPriority->sortRows($governorateRows->items(), 'item_category', 'item_category')
                ));

                return view('reports.cities.index', [
                    'mode' => 'governorate_breakdown',
                    'rows' => null,
                    'totals' => null,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'salesmanOptions' => $salesmanOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => $governorateRows,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieSeriesBySalesman' => [],
                    'pieCategoryOptions' => $pieCategoryOptions,
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'hasCityColumn' => $hasCityColumn,
                    'errorMessage' => null,
                ]);
            }

            if ($cityPage === 'pie-charts') {
                $pieSeriesByCity = $this->repository->getPieByCitySeries(
                    $dateFrom,
                    $dateTo,
                    $cities,
                    $excludeCategory !== '' ? $excludeCategory : null,
                    $salesmanIds
                );
                $pieSeriesByCategory = $this->repository->getPieByCategorySeries(
                    $dateFrom,
                    $dateTo,
                    $cities,
                    $excludeCategory !== '' ? $excludeCategory : null,
                    $salesmanIds
                );
                $pieSeriesByItem = $this->repository->getPieByItemSeries(
                    $dateFrom,
                    $dateTo,
                    $cities,
                    $pieCategory !== '' ? $pieCategory : null,
                    $excludeCategory !== '' ? $excludeCategory : null,
                    $salesmanIds
                );

                return view('reports.cities.index', [
                    'mode' => 'pie_charts',
                    'rows' => null,
                    'totals' => null,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'salesmanOptions' => $salesmanOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => null,
                    'pieSeriesByCity' => $pieSeriesByCity,
                    'pieSeriesByCategory' => $pieSeriesByCategory,
                    'pieSeriesByItem' => $pieSeriesByItem,
                    'pieSeriesBySalesman' => [],
                    'pieCategoryOptions' => $pieCategoryOptions,
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'hasCityColumn' => $hasCityColumn,
                    'errorMessage' => null,
                ]);
            }

            if ($cityPage === 'salesman-pie') {
                $salesmanRows = $this->repository->getPieBySalesmanSeries(
                    $dateFrom,
                    $dateTo,
                    $cities,
                    $excludeCategory !== '' ? $excludeCategory : null,
                    $salesmanIds
                );
                $salesmenById = collect($salesmanOptions)->keyBy('id');
                $pieSeriesBySalesman = array_values(array_map(
                    static function (object $row) use ($salesmenById): object {
                        $salesmanId = trim((string) ($row->salesman_id ?? ''));
                        $salesmanRow = $salesmanId !== '' ? $salesmenById->get($salesmanId) : null;
                        $label = $salesmanId !== ''
                            ? (string) ((is_array($salesmanRow) ? ($salesmanRow['name'] ?? $salesmanId) : $salesmanId))
                            : '(unassigned)';

                        return (object) [
                            'salesman_name' => $label,
                            'amount' => $row->amount ?? 0,
                        ];
                    },
                    $salesmanRows
                ));

                return view('reports.cities.index', [
                    'mode' => 'salesman_pie',
                    'rows' => null,
                    'totals' => null,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'salesmanOptions' => $salesmanOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => null,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieSeriesBySalesman' => $pieSeriesBySalesman,
                    'pieCategoryOptions' => $pieCategoryOptions,
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'hasCityColumn' => $hasCityColumn,
                    'errorMessage' => null,
                ]);
            }

            if ($panel === 'charts') {
                return view('reports.cities.index', [
                    'mode' => 'charts',
                    'rows' => null,
                    'totals' => null,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'salesmanOptions' => $salesmanOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => $chartTimeSeries,
                    'governorateRows' => null,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieCategoryOptions' => [],
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'hasCityColumn' => $hasCityColumn,
                    'errorMessage' => null,
                ]);
            }

            if ($breakdownByClient) {
                $result = $this->repository->getChickenCategoryBreakdownByClient(
                    $dateFrom,
                    $dateTo,
                    $q !== '' ? $q : null,
                    $page,
                    $perPage,
                    $cities,
                    $salesmanIds
                );
                $result->setCollection(collect(
                    $this->assemblyPriority->sortRows($result->items(), 'chicken_category', 'chicken_category')
                ));

                return view('reports.cities.index', [
                    'mode' => 'by_category_by_client',
                    'rows' => $result,
                    'totals' => null,
                    'grandTotals' => $grandTotals,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'salesmanOptions' => $salesmanOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => null,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieCategoryOptions' => [],
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'hasCityColumn' => $hasCityColumn,
                    'errorMessage' => null,
                ]);
            }

            if ($breakdown) {
                $result = $this->repository->getChickenCategoryBreakdown(
                    $dateFrom,
                    $dateTo,
                    $q !== '' ? $q : null,
                    $page,
                    $perPage,
                    $cities,
                    $salesmanIds
                );
                $result->setCollection(collect(
                    $this->assemblyPriority->sortRows($result->items(), 'chicken_category', 'chicken_category')
                ));

                return view('reports.cities.index', [
                    'mode' => 'by_category',
                    'rows' => $result,
                    'totals' => null,
                    'grandTotals' => $grandTotals,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'salesmanOptions' => $salesmanOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => null,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieCategoryOptions' => [],
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'hasCityColumn' => $hasCityColumn,
                    'errorMessage' => null,
                ]);
            }

            $result = $this->repository->getReport(
                $dateFrom,
                $dateTo,
                $groupByClient,
                $page,
                $perPage,
                $cities,
                $salesmanIds
            );

            if ($groupByClient && $result instanceof LengthAwarePaginator) {
                return view('reports.cities.index', [
                    'mode' => 'by_client',
                    'rows' => $result,
                    'totals' => null,
                    'grandTotals' => $grandTotals,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'salesmanOptions' => $salesmanOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => null,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieCategoryOptions' => [],
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'hasCityColumn' => $hasCityColumn,
                    'errorMessage' => null,
                ]);
            }

            $totals = is_array($result) && isset($result[0]) ? $result[0] : null;

            return view('reports.cities.index', [
                'mode' => 'totals',
                'rows' => null,
                'totals' => $totals,
                'grandTotals' => $grandTotals ?? $totals,
                'filters' => $filters,
                'cityOptions' => $cityOptions,
                'salesmanOptions' => $salesmanOptions,
                'cityNames' => $cityNames,
                'chartTimeSeries' => [],
                'governorateRows' => null,
                'pieSeriesByCity' => [],
                'pieSeriesByCategory' => [],
                'pieSeriesByItem' => [],
                'pieCategoryOptions' => [],
                'savedGovernorates' => $savedGovernorates,
                'governorateStorageError' => $governorateStorageError,
                'hasCityColumn' => $hasCityColumn,
                'errorMessage' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Cities report failed.', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'panel' => $panel,
                'message' => $exception->getMessage(),
            ]);

            return view('reports.cities.index', [
                'mode' => $breakdownByClient
                    ? 'by_category_by_client'
                    : ($breakdown ? 'by_category' : ($groupByClient ? 'by_client' : 'totals')),
                'rows' => null,
                'totals' => null,
                'filters' => $filters,
                'cityOptions' => $cityOptions,
                'salesmanOptions' => $salesmanOptions,
                'cityNames' => $cityNames,
                'chartTimeSeries' => [],
                'governorateRows' => $governorateRows,
                'pieSeriesByCity' => $pieSeriesByCity,
                'pieSeriesByCategory' => $pieSeriesByCategory,
                'pieSeriesByItem' => $pieSeriesByItem,
                'pieSeriesBySalesman' => $pieSeriesBySalesman,
                'pieCategoryOptions' => $pieCategoryOptions,
                'savedGovernorates' => $savedGovernorates,
                'governorateStorageError' => $governorateStorageError,
                'hasCityColumn' => $hasCityColumn,
                'errorMessage' => 'Unable to load cities report. Check logs and try again.',
            ]);
        }
    }

    public function exportPdf(CitiesReportRequest $request): Response|RedirectResponse
    {
        $input = array_merge([
            'group_by_client' => true,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'q' => '',
            'cities' => [],
            'salesman_ids' => [],
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $salesmanIds = $this->repository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );

        $exportMode = $this->resolveExportMode($input);

        try {
            $rows = $this->fetchExportRows($exportMode, $dateFrom, $dateTo, $q, $cities, $salesmanIds);
        } catch (Throwable $e) {
            Log::error('Cities PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.cities.index', $request->query()))
                ->with('error', 'Could not export PDF. Check logs and try a narrower date range or fewer filters.');
        }

        $pdf = Pdf::loadView('reports.cities.export-pdf', [
            'mode' => $exportMode,
            'rows' => $rows,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'q' => $q,
            'modeLabel' => $this->exportModeLabel($exportMode),
            'citiesLabel' => $this->cityFilterLabel($cities),
            'salesmenLabel' => $this->salesmanFilterLabel($salesmanIds),
            'exportCap' => self::EXPORT_ROW_CAP,
            ...\App\Support\ReportPdfBranding::viewData(),
        ])->setPaper('a4', $exportMode === 'by_category_by_client' ? 'landscape' : 'portrait');

        $filename = 'cities-sales-'.$dateFrom.'-'.$dateTo.'.pdf';

        return $pdf->download($filename);
    }

    public function exportChartPdf(CitiesReportRequest $request): Response|RedirectResponse
    {
        $input = array_merge([
            'group_by_client' => true,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'q' => '',
            'cities' => [],
            'salesman_ids' => [],
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $salesmanIds = $this->repository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );

        try {
            $rows = $this->repository->getSalesOverTimeChartSeries($dateFrom, $dateTo, $cities, $salesmanIds);
        } catch (Throwable $e) {
            Log::error('Cities chart PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.cities.index', $request->query()))
                ->with('error', 'Could not export chart PDF. Check logs and try again.');
        }

        if ($rows === []) {
            return redirect()
                ->to(route('reports.cities.index', $request->query()))
                ->with('error', 'No chart data to export for the selected filters.');
        }

        $chartShow = SvgSalesTimeSeriesChart::normalizeSeriesKeys(
            is_array($input['chart_show'] ?? null) ? $input['chart_show'] : null
        );

        $pdf = Pdf::loadView('reports.cities.chart-pdf', [
            'rows' => $rows,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'citiesLabel' => $this->cityFilterLabel($cities),
            'salesmenLabel' => $this->salesmanFilterLabel($salesmanIds),
            'chartShow' => $chartShow,
            ...\App\Support\ReportPdfBranding::viewData(),
        ])->setPaper('a4', 'landscape');

        $filename = 'cities-sales-chart-'.$dateFrom.'-'.$dateTo.'.pdf';

        return $pdf->download($filename);
    }

    public function exportCsv(CitiesReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = array_merge([
            'group_by_client' => true,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'q' => '',
            'cities' => [],
            'salesman_ids' => [],
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $salesmanIds = $this->repository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );

        $exportMode = $this->resolveExportMode($input);

        try {
            $rows = $this->fetchExportRows($exportMode, $dateFrom, $dateTo, $q, $cities, $salesmanIds);
        } catch (Throwable $e) {
            Log::error('Cities CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.cities.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        $filename = 'cities-sales-'.$dateFrom.'-'.$dateTo.'.csv';

        return Excel::download(
            new SalesReportExport($rows, $exportMode),
            $filename,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function cityOptionsForPicker(): array
    {
        $cities = $this->visitsRepository->getCityOptions();
        $out = [];
        foreach ($cities as $c) {
            if ($c !== '') {
                $out[] = ['id' => $c, 'name' => $c];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return 'totals'|'by_client'|'by_category'|'by_category_by_client'
     */
    private function resolveExportMode(array $input): string
    {
        if (! empty($input['breakdown_by_client'])) {
            return 'by_category_by_client';
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
     * @return list<stdClass>
     */
    private function fetchExportRows(
        string $exportMode,
        string $dateFrom,
        string $dateTo,
        string $q,
        array $cities,
        array $salesmanIds
    ): array {
        $qOrNull = $q !== '' ? $q : null;

        return match ($exportMode) {
            'by_category_by_client' => $this->repository->exportChickenCategoryByClientRows(
                $dateFrom,
                $dateTo,
                $qOrNull,
                $cities,
                $salesmanIds
            ),
            'by_category' => $this->repository->exportChickenCategoryRows(
                $dateFrom,
                $dateTo,
                $qOrNull,
                $cities,
                $salesmanIds
            ),
            'by_client' => $this->repository->exportReportRows(
                $dateFrom,
                $dateTo,
                true,
                $cities,
                $salesmanIds
            ),
            default => $this->repository->exportReportRows(
                $dateFrom,
                $dateTo,
                false,
                $cities,
                $salesmanIds
            ),
        };
    }

    /**
     * @param  list<string>  $cities
     */
    private function cityFilterLabel(array $cities): string
    {
        return $cities === [] ? '' : implode('; ', $cities);
    }

    /**
     * @param  list<string>  $salesmanIds
     */
    private function salesmanFilterLabel(array $salesmanIds): string
    {
        if ($salesmanIds === []) {
            return '';
        }

        $all = collect($this->visitsRepository->getSalesmanOptions())->keyBy('id');
        $labels = [];
        foreach ($salesmanIds as $salesmanId) {
            $row = $all->get($salesmanId);
            $labels[] = is_array($row) ? (string) ($row['name'] ?? $salesmanId) : $salesmanId;
        }

        return implode('; ', $labels);
    }

    private function exportModeLabel(string $mode): string
    {
        return match ($mode) {
            'by_category_by_client' => 'Category by client',
            'by_category' => 'Category breakdown',
            'by_client' => 'By client',
            'totals' => 'Period totals',
            default => $mode,
        };
    }
}
