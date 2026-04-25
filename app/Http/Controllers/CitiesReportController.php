<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SalesReportExport;
use App\Http\Requests\CitiesReportRequest;
use App\Repositories\CitiesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Support\SvgSalesTimeSeriesChart;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
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
        private readonly VisitsReportRepository $visitsRepository
    ) {
    }

    public function index(CitiesReportRequest $request): View
    {
        $govService = app(CitiesGovernorateSqliteService::class);
        $today = Carbon::now()->toDateString();
        $defaults = [
            'date_from' => $today,
            'date_to' => $today,
            'group_by_client' => true,
            'per_page' => 25,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'q' => '',
            'cities' => [],
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
        $perPage = (int) ($input['per_page'] ?? 25);
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
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        $savedGovernorates = [];
        $editingGovernorate = null;
        $governorateStorageError = null;
        try {
            $savedGovernorates = $govService->listGovernorates();
            $editingGovernorate = $editGovernorateId > 0 ? $govService->getGovernorateById($editGovernorateId) : null;
            if ($savedGovernorateId > 0) {
                $selectedGovernorate = $govService->getGovernorateById($savedGovernorateId);
                if ($selectedGovernorate !== null) {
                    $governorateCity = $selectedGovernorate['governorate_city'];
                    $governorateMembers = $this->repository->normalizeCities($selectedGovernorate['members']);
                    if ($cityPage === 'pie-charts') {
                        $cities = $this->repository->normalizeCities($selectedGovernorate['members']);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('cities.governorate_storage_unavailable', ['message' => $e->getMessage()]);
            $savedGovernorates = [];
            $editingGovernorate = null;
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
            'panel' => $panel,
            'city_page' => $cityPage,
            'governorate_city' => $governorateCity,
            'governorate_members' => $governorateMembers,
            'pie_category' => $pieCategory,
            'exclude_category' => $excludeCategory,
            'saved_governorate_id' => $savedGovernorateId > 0 ? $savedGovernorateId : '',
            'edit_governorate_id' => $editGovernorateId > 0 ? $editGovernorateId : '',
        ];

        $cityOptions = $this->cityOptionsForPicker();
        $cityNames = array_values(array_map(
            static fn (array $c): string => (string) ($c['id'] ?? ''),
            $cityOptions
        ));
        $hasCityColumn = $this->visitsRepository->getAccountCityColumnName() !== null;

        $chartTimeSeries = [];
        if ($panel === 'charts' && $cityPage === 'overview') {
            try {
                $chartTimeSeries = $this->repository->getSalesOverTimeChartSeries($dateFrom, $dateTo, $cities);
            } catch (Throwable $e) {
                Log::warning('cities.chart_time_series_failed', ['message' => $e->getMessage()]);
                $chartTimeSeries = [];
            }
        }

        $governorateRows = null;
        $pieSeriesByCity = [];
        $pieSeriesByCategory = [];
        $pieSeriesByItem = [];
        $pieCategoryOptions = [];
        if ($cityPage === 'governorate-breakdown' || $cityPage === 'pie-charts') {
            try {
                $pieCategoryOptions = $this->repository->getItemCategoryOptions($dateFrom, $dateTo, $cities);
            } catch (Throwable $e) {
                Log::warning('cities.category_options_failed', ['message' => $e->getMessage()]);
                $pieCategoryOptions = [];
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
                    $perPage
                );

                return view('reports.cities.index', [
                    'mode' => 'governorate_breakdown',
                    'rows' => null,
                    'totals' => null,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => $governorateRows,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieCategoryOptions' => $pieCategoryOptions,
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'editingGovernorate' => $editingGovernorate,
                    'hasCityColumn' => $hasCityColumn,
                    'errorMessage' => null,
                ]);
            }

            if ($cityPage === 'pie-charts') {
                $pieSeriesByCity = $this->repository->getPieByCitySeries(
                    $dateFrom,
                    $dateTo,
                    $cities,
                    $excludeCategory !== '' ? $excludeCategory : null
                );
                $pieSeriesByCategory = $this->repository->getPieByCategorySeries(
                    $dateFrom,
                    $dateTo,
                    $cities,
                    $excludeCategory !== '' ? $excludeCategory : null
                );
                $pieSeriesByItem = $this->repository->getPieByItemSeries(
                    $dateFrom,
                    $dateTo,
                    $cities,
                    $pieCategory !== '' ? $pieCategory : null,
                    $excludeCategory !== '' ? $excludeCategory : null
                );

                return view('reports.cities.index', [
                    'mode' => 'pie_charts',
                    'rows' => null,
                    'totals' => null,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => null,
                    'pieSeriesByCity' => $pieSeriesByCity,
                    'pieSeriesByCategory' => $pieSeriesByCategory,
                    'pieSeriesByItem' => $pieSeriesByItem,
                    'pieCategoryOptions' => $pieCategoryOptions,
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'editingGovernorate' => $editingGovernorate,
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
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => $chartTimeSeries,
                    'governorateRows' => null,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieCategoryOptions' => [],
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'editingGovernorate' => $editingGovernorate,
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
                    $cities
                );

                return view('reports.cities.index', [
                    'mode' => 'by_category_by_client',
                    'rows' => $result,
                    'totals' => null,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => null,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieCategoryOptions' => [],
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'editingGovernorate' => $editingGovernorate,
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
                    $cities
                );

                return view('reports.cities.index', [
                    'mode' => 'by_category',
                    'rows' => $result,
                    'totals' => null,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => null,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieCategoryOptions' => [],
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'editingGovernorate' => $editingGovernorate,
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
                $cities
            );

            if ($groupByClient && $result instanceof LengthAwarePaginator) {
                return view('reports.cities.index', [
                    'mode' => 'by_client',
                    'rows' => $result,
                    'totals' => null,
                    'filters' => $filters,
                    'cityOptions' => $cityOptions,
                    'cityNames' => $cityNames,
                    'chartTimeSeries' => [],
                    'governorateRows' => null,
                    'pieSeriesByCity' => [],
                    'pieSeriesByCategory' => [],
                    'pieSeriesByItem' => [],
                    'pieCategoryOptions' => [],
                    'savedGovernorates' => $savedGovernorates,
                    'governorateStorageError' => $governorateStorageError,
                    'editingGovernorate' => $editingGovernorate,
                    'hasCityColumn' => $hasCityColumn,
                    'errorMessage' => null,
                ]);
            }

            $totals = is_array($result) && isset($result[0]) ? $result[0] : null;

            return view('reports.cities.index', [
                'mode' => 'totals',
                'rows' => null,
                'totals' => $totals,
                'filters' => $filters,
                'cityOptions' => $cityOptions,
                'cityNames' => $cityNames,
                'chartTimeSeries' => [],
                'governorateRows' => null,
                'pieSeriesByCity' => [],
                'pieSeriesByCategory' => [],
                'pieSeriesByItem' => [],
                'pieCategoryOptions' => [],
                'savedGovernorates' => $savedGovernorates,
                'governorateStorageError' => $governorateStorageError,
                'editingGovernorate' => $editingGovernorate,
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
                'cityNames' => $cityNames,
                'chartTimeSeries' => [],
                'governorateRows' => $governorateRows,
                'pieSeriesByCity' => $pieSeriesByCity,
                'pieSeriesByCategory' => $pieSeriesByCategory,
                'pieSeriesByItem' => $pieSeriesByItem,
                'pieCategoryOptions' => $pieCategoryOptions,
                'savedGovernorates' => $savedGovernorates,
                'governorateStorageError' => $governorateStorageError,
                'editingGovernorate' => $editingGovernorate,
                'hasCityColumn' => $hasCityColumn,
                'errorMessage' => 'Unable to load cities report. Check logs and try again.',
            ]);
        }
    }

    public function saveGovernorate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'governorate_id' => ['nullable', 'integer', 'min:1'],
            'governorate_name' => ['required', 'string', 'max:200'],
            'governorate_city' => ['required', 'string', 'max:200'],
            'governorate_members' => ['sometimes', 'array', 'max:500'],
            'governorate_members.*' => ['string', 'max:200'],
        ]);

        try {
            $govId = app(CitiesGovernorateSqliteService::class)->saveGovernorate(
                isset($validated['governorate_id']) ? (int) $validated['governorate_id'] : null,
                trim((string) $validated['governorate_name']),
                trim((string) $validated['governorate_city']),
                is_array($validated['governorate_members'] ?? null) ? $validated['governorate_members'] : []
            );
        } catch (Throwable $e) {
            return redirect()->route('reports.cities.index', array_merge($request->query(), [
                'city_page' => 'governorate-breakdown',
            ]))->with('error', 'Could not save governorate: '.$e->getMessage());
        }

        return redirect()->route('reports.cities.index', array_merge($request->query(), [
            'city_page' => 'governorate-breakdown',
            'saved_governorate_id' => $govId,
            'edit_governorate_id' => $govId,
        ]))->with('status', 'Governorate saved.');
    }

    public function exportPdf(CitiesReportRequest $request): Response|RedirectResponse
    {
        $input = array_merge([
            'group_by_client' => true,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'q' => '',
            'cities' => [],
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        $exportMode = $this->resolveExportMode($input);

        try {
            $rows = $this->fetchExportRows($exportMode, $dateFrom, $dateTo, $q, $cities);
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
            'exportCap' => self::EXPORT_ROW_CAP,
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
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        try {
            $rows = $this->repository->getSalesOverTimeChartSeries($dateFrom, $dateTo, $cities);
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
            'chartShow' => $chartShow,
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
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        $exportMode = $this->resolveExportMode($input);

        try {
            $rows = $this->fetchExportRows($exportMode, $dateFrom, $dateTo, $q, $cities);
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
        array $cities
    ): array {
        $qOrNull = $q !== '' ? $q : null;

        return match ($exportMode) {
            'by_category_by_client' => $this->repository->exportChickenCategoryByClientRows(
                $dateFrom,
                $dateTo,
                $qOrNull,
                $cities
            ),
            'by_category' => $this->repository->exportChickenCategoryRows(
                $dateFrom,
                $dateTo,
                $qOrNull,
                $cities
            ),
            'by_client' => $this->repository->exportReportRows(
                $dateFrom,
                $dateTo,
                true,
                $cities
            ),
            default => $this->repository->exportReportRows(
                $dateFrom,
                $dateTo,
                false,
                $cities
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
