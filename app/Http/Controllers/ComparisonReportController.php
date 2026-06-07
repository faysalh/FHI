<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ComparisonReportExport;
use App\Http\Requests\ComparisonReportRequest;
use App\Repositories\ComparisonReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Services\ReportAssemblyPriorityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ComparisonReportController extends Controller
{
    public function __construct(
        private readonly ComparisonReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly CitiesGovernorateSqliteService $governorates,
        private readonly ReportAssemblyPriorityService $assemblyPriorityService
    ) {}

    public function index(ComparisonReportRequest $request): View
    {
        $viewData = $this->buildViewData($request);

        return view('reports.comparison.index', $viewData);
    }

    public function exportPdf(ComparisonReportRequest $request): Response|RedirectResponse
    {
        $viewData = $this->buildViewData($request);
        if (($viewData['errorMessage'] ?? null) !== null) {
            return redirect()->to(route('reports.comparison.index', $request->query()))
                ->with('error', 'Could not export PDF. Check logs and try again.');
        }

        $pdf = Pdf::loadView('reports.comparison.pdf', $viewData)->setPaper('a4', 'landscape');

        return $pdf->download('comparison-report.pdf');
    }

    public function exportCsv(ComparisonReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $viewData = $this->buildViewData($request);
        if (($viewData['errorMessage'] ?? null) !== null) {
            return redirect()->to(route('reports.comparison.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        [$headings, $rows] = $this->buildExportRows($viewData);

        return Excel::download(
            new ComparisonReportExport($rows, $headings),
            'comparison-report.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(ComparisonReportRequest $request): array
    {
        $input = $request->validated();
        $dateFrom1 = (string) $input['date_from_1'];
        $dateTo1 = (string) $input['date_to_1'];
        $dateFrom2 = (string) $input['date_from_2'];
        $dateTo2 = (string) $input['date_to_2'];
        $salesmanId = trim((string) ($input['salesman_id'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $savedGovernorateId = (int) ($input['saved_governorate_id'] ?? 0);
        $excludeCategory = trim((string) ($input['exclude_category'] ?? ''));
        $metrics = is_array($input['metrics'] ?? null) ? array_values($input['metrics']) : ['quantity', 'amount', 'weight'];

        $cityOptions = $this->visitsRepository->getCityOptions();
        $salesmanOptions = $this->visitsRepository->getSalesmanOptions();
        $salesmanLabel = 'All';
        if ($salesmanId !== '') {
            foreach ($salesmanOptions as $salesmanOption) {
                if ((string) ($salesmanOption['id'] ?? '') === $salesmanId) {
                    $salesmanLabel = (string) ($salesmanOption['name'] ?? $salesmanId);
                    break;
                }
            }
        }

        $savedGovernorates = [];
        $governorateCities = [];
        $governorateLabel = 'None';
        try {
            $savedGovernorates = $this->governorates->listGovernorates();
            if ($savedGovernorateId > 0) {
                $selectedGov = $this->governorates->getGovernorateById($savedGovernorateId);
                if ($selectedGov !== null) {
                    $governorateCities = (array) ($selectedGov['members'] ?? []);
                    $governorateLabel = (string) ($selectedGov['name'] ?? 'None');
                }
            }
        } catch (Throwable $e) {
            Log::warning('comparison.governorates_unavailable', ['message' => $e->getMessage()]);
            $savedGovernorates = [];
            $governorateCities = [];
        }

        $citiesFilter = [];
        if ($city !== '') {
            $citiesFilter[] = $city;
        }
        if ($governorateCities !== []) {
            $citiesFilter = array_values(array_unique(array_merge($citiesFilter, $governorateCities)));
        }

        $errorMessage = null;
        $comparisonRows = [];
        $categoryOptions = [];
        $branding = InvoiceBrandingSettingsController::getSettings();
        $effectiveExclude = $excludeCategory !== '' ? $excludeCategory : null;
        try {
            $period1Rows = $this->repository->getItemRows($dateFrom1, $dateTo1, $citiesFilter, $salesmanId !== '' ? $salesmanId : null, $effectiveExclude);
            $period2Rows = $this->repository->getItemRows($dateFrom2, $dateTo2, $citiesFilter, $salesmanId !== '' ? $salesmanId : null, $effectiveExclude);
            $comparisonRows = $this->mergePeriodRows($period1Rows, $period2Rows);
            $comparisonRows = $this->assemblyPriorityService->sortRows($comparisonRows, 'category_name', 'item_name');

            $allDates = [$dateFrom1, $dateTo1, $dateFrom2, $dateTo2];
            sort($allDates);
            $categoryOptions = $this->repository->getCategoryOptions(
                $allDates[0],
                $allDates[3],
                $citiesFilter,
                $salesmanId !== '' ? $salesmanId : null
            );
        } catch (Throwable $e) {
            Log::error('comparison.report_failed', ['message' => $e->getMessage()]);
            $errorMessage = 'Unable to load comparison report. Check logs and try again.';
        }

        return [
            'filters' => [
                'date_from_1' => $dateFrom1,
                'date_to_1' => $dateTo1,
                'date_from_2' => $dateFrom2,
                'date_to_2' => $dateTo2,
                'salesman_id' => $salesmanId,
                'city' => $city,
                'saved_governorate_id' => $savedGovernorateId > 0 ? $savedGovernorateId : '',
                'metrics' => $metrics,
                'exclude_category' => $excludeCategory,
            ],
            'salesmanLabel' => $salesmanLabel,
            'governorateLabel' => $governorateLabel,
            'cityOptions' => $cityOptions,
            'salesmanOptions' => $salesmanOptions,
            'savedGovernorates' => $savedGovernorates,
            'categoryOptions' => $categoryOptions,
            'rows' => $comparisonRows,
            'groupedRows' => $this->groupRowsByCategoryWithGrowth($comparisonRows),
            'totals' => $this->enrichTotalsWithGrowth($this->calculateTotals($comparisonRows)),
            ...\App\Support\ReportPdfBranding::viewData($branding),
            'errorMessage' => $errorMessage,
        ];
    }

    /**
     * @param  list<object>  $period1Rows
     * @param  list<object>  $period2Rows
     * @return list<object>
     */
    private function mergePeriodRows(array $period1Rows, array $period2Rows): array
    {
        $merged = [];
        foreach ($period1Rows as $row) {
            $category = trim((string) ($row->category_name ?? ''));
            $item = trim((string) ($row->item_name ?? ''));
            $key = mb_strtolower($category).'|'.mb_strtolower($item);
            $merged[$key] = (object) [
                'category_name' => $category,
                'item_name' => $item,
                'period1_quantity' => (float) ($row->quantity_total ?? 0),
                'period1_amount' => (float) ($row->amount_total ?? 0),
                'period1_weight' => (float) ($row->weight_total ?? 0),
                'period2_quantity' => 0.0,
                'period2_amount' => 0.0,
                'period2_weight' => 0.0,
            ];
        }
        foreach ($period2Rows as $row) {
            $category = trim((string) ($row->category_name ?? ''));
            $item = trim((string) ($row->item_name ?? ''));
            $key = mb_strtolower($category).'|'.mb_strtolower($item);
            if (! isset($merged[$key])) {
                $merged[$key] = (object) [
                    'category_name' => $category,
                    'item_name' => $item,
                    'period1_quantity' => 0.0,
                    'period1_amount' => 0.0,
                    'period1_weight' => 0.0,
                    'period2_quantity' => 0.0,
                    'period2_amount' => 0.0,
                    'period2_weight' => 0.0,
                ];
            }
            $merged[$key]->period2_quantity = (float) ($row->quantity_total ?? 0);
            $merged[$key]->period2_amount = (float) ($row->amount_total ?? 0);
            $merged[$key]->period2_weight = (float) ($row->weight_total ?? 0);
        }

        foreach ($merged as $key => $row) {
            $row->diff_quantity = (float) $row->period2_quantity - (float) $row->period1_quantity;
            $row->diff_amount = (float) $row->period2_amount - (float) $row->period1_amount;
            $row->diff_weight = (float) $row->period2_weight - (float) $row->period1_weight;
            $merged[$key] = $row;
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $viewData
     * @return array{0:list<string>,1:array<int,array<int,string|int|float>>}
     */
    private function buildExportRows(array $viewData): array
    {
        $metrics = is_array($viewData['filters']['metrics'] ?? null) ? $viewData['filters']['metrics'] : ['quantity', 'amount', 'weight'];
        $headings = ['Category', 'Item'];
        foreach ($metrics as $metric) {
            $headings[] = 'P1 '.$this->metricLabel((string) $metric);
        }
        foreach ($metrics as $metric) {
            $headings[] = 'P2 '.$this->metricLabel((string) $metric);
        }
        foreach ($metrics as $metric) {
            $headings[] = 'Diff '.$this->metricLabel((string) $metric);
        }

        $rows = [];
        $groups = is_array($viewData['groupedRows'] ?? null) ? $viewData['groupedRows'] : [];
        foreach ($groups as $group) {
            $category = (string) ($group['category'] ?? '');
            $groupRows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
            foreach ($groupRows as $row) {
                $line = [
                    (string) ($row->category_name ?? ''),
                    (string) ($row->item_name ?? ''),
                ];
                foreach ($metrics as $metric) {
                    $line[] = $this->formatMetricValue((string) $metric, (float) ($row->{'period1_'.$metric} ?? 0));
                }
                foreach ($metrics as $metric) {
                    $line[] = $this->formatMetricValue((string) $metric, (float) ($row->{'period2_'.$metric} ?? 0));
                }
                foreach ($metrics as $metric) {
                    $line[] = $this->formatMetricValue((string) $metric, (float) ($row->{'diff_'.$metric} ?? 0));
                }
                $rows[] = $line;
            }

            $groupTotals = is_array($group['totals'] ?? null) ? $group['totals'] : [];
            $subtotalLine = ['Subtotal: '.$category, ''];
            foreach ($metrics as $metric) {
                $subtotalLine[] = $this->formatMetricValue((string) $metric, (float) ($groupTotals['period1_'.$metric] ?? 0));
            }
            foreach ($metrics as $metric) {
                $subtotalLine[] = $this->formatMetricValue((string) $metric, (float) ($groupTotals['period2_'.$metric] ?? 0));
            }
            foreach ($metrics as $metric) {
                $subtotalLine[] = $this->formatMetricValue((string) $metric, (float) ($groupTotals['diff_'.$metric] ?? 0));
            }
            $rows[] = $subtotalLine;
            $rows[] = $this->buildGrowthExportLine('Growth %: '.$category, $metrics, $groupTotals);
        }

        $totals = is_array($viewData['totals'] ?? null) ? $viewData['totals'] : [];
        if ($totals !== []) {
            $totalLine = ['TOTAL', ''];
            foreach ($metrics as $metric) {
                $totalLine[] = $this->formatMetricValue((string) $metric, (float) ($totals['period1_'.$metric] ?? 0));
            }
            foreach ($metrics as $metric) {
                $totalLine[] = $this->formatMetricValue((string) $metric, (float) ($totals['period2_'.$metric] ?? 0));
            }
            foreach ($metrics as $metric) {
                $totalLine[] = $this->formatMetricValue((string) $metric, (float) ($totals['diff_'.$metric] ?? 0));
            }
            $rows[] = $totalLine;
            $rows[] = $this->buildGrowthExportLine('Growth %', $metrics, $totals);
        }

        return [$headings, $rows];
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, float>
     */
    private function calculateTotals(array $rows): array
    {
        $totals = [
            'period1_quantity' => 0.0,
            'period1_amount' => 0.0,
            'period1_weight' => 0.0,
            'period2_quantity' => 0.0,
            'period2_amount' => 0.0,
            'period2_weight' => 0.0,
            'diff_quantity' => 0.0,
            'diff_amount' => 0.0,
            'diff_weight' => 0.0,
        ];

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $column) {
                $totals[$column] += (float) ($row->{$column} ?? 0);
            }
        }

        return $totals;
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{category:string,rows:list<object>,totals:array<string,float|null>}>
     */
    private function groupRowsByCategoryWithGrowth(array $rows): array
    {
        /** @var array<string, array{category:string,rows:list<object>,totals:array<string,float>}> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $category = trim((string) ($row->category_name ?? ''));
            $key = mb_strtolower($category);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'category' => $category,
                    'rows' => [],
                    'totals' => [
                        'period1_quantity' => 0.0,
                        'period1_amount' => 0.0,
                        'period1_weight' => 0.0,
                        'period2_quantity' => 0.0,
                        'period2_amount' => 0.0,
                        'period2_weight' => 0.0,
                        'diff_quantity' => 0.0,
                        'diff_amount' => 0.0,
                        'diff_weight' => 0.0,
                    ],
                ];
            }

            $groups[$key]['rows'][] = $row;
            foreach (array_keys($groups[$key]['totals']) as $column) {
                if (! str_starts_with($column, 'growth_')) {
                    $groups[$key]['totals'][$column] += (float) ($row->{$column} ?? 0);
                }
            }
        }

        return array_map(function (array $group): array {
            $group['totals'] = $this->enrichTotalsWithGrowth($group['totals']);

            return $group;
        }, array_values($groups));
    }

    /**
     * @param  array<string, float>  $totals
     * @return array<string, float|null>
     */
    private function enrichTotalsWithGrowth(array $totals): array
    {
        foreach (['quantity', 'amount', 'weight'] as $metric) {
            $period1 = (float) ($totals['period1_'.$metric] ?? 0);
            $period2 = (float) ($totals['period2_'.$metric] ?? 0);
            $totals['growth_'.$metric] = $this->growthPercent($period1, $period2);
        }

        return $totals;
    }

    private function growthPercent(float $period1, float $period2): ?float
    {
        if ($period1 == 0.0) {
            return null;
        }

        return (($period2 - $period1) / $period1) * 100.0;
    }

    private function formatGrowthPercent(?float $growth): string
    {
        if ($growth === null) {
            return '—';
        }

        $formatted = \App\Support\NumberDisplay::format($growth);

        return ($growth > 0.0 ? '+' : '').$formatted.'%';
    }

    /**
     * @param  list<string>  $metrics
     * @param  array<string, float|null>  $totals
     * @return list<string>
     */
    private function buildGrowthExportLine(string $label, array $metrics, array $totals): array
    {
        $line = [$label, ''];
        foreach ($metrics as $metric) {
            $line[] = '';
        }
        foreach ($metrics as $metric) {
            $line[] = '';
        }
        foreach ($metrics as $metric) {
            $growth = $totals['growth_'.$metric] ?? null;
            $line[] = is_float($growth) ? $this->formatGrowthPercent($growth) : '—';
        }

        return $line;
    }

    private function metricLabel(string $metric): string
    {
        return match ($metric) {
            'quantity' => 'Quantity (carton)',
            'amount' => 'Amount (IQD)',
            'weight' => 'Weight (kg)',
            default => ucfirst($metric),
        };
    }

    private function formatMetricValue(string $metric, float $value): string
    {
        if ($metric === 'amount') {
            return 'IQD '.\App\Support\NumberDisplay::format($value);
        }

        return \App\Support\NumberDisplay::format($value);
    }

    /**
     * @param  array<string, string>  $branding
     */
    private function resolveBrandingLogoDataUri(array $branding): ?string
    {
        $logoPath = trim((string) ($branding['logo_path'] ?? ''));
        if ($logoPath === '') {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($logoPath)) {
                return null;
            }
            $mime = (string) ($disk->mimeType($logoPath) ?? 'image/png');
            $contents = $disk->get($logoPath);

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (Throwable $e) {
            Log::warning('comparison_logo_data_uri_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
