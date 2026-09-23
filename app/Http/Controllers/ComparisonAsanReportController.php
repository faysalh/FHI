<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ComparisonReportExport;
use App\Http\Controllers\Concerns\BuildsComparisonPeriodView;
use App\Http\Requests\ComparisonReportRequest;
use App\Repositories\ComparisonAsanReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Services\ReportAssemblyPriorityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ComparisonAsanReportController extends Controller
{
    use BuildsComparisonPeriodView;

    public function __construct(
        private readonly ComparisonAsanReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly CitiesGovernorateSqliteService $governorates,
        private readonly ReportAssemblyPriorityService $assemblyPriorityService
    ) {}

    public function index(ComparisonReportRequest $request): View
    {
        $viewData = $this->buildViewData($request);

        return view('reports.comparison.asan.index', $viewData);
    }

    public function exportPdf(ComparisonReportRequest $request): Response|RedirectResponse
    {
        $viewData = $this->buildViewData($request);
        if (($viewData['errorMessage'] ?? null) !== null) {
            return redirect()->to(route('reports.comparison.asan.index', $request->query()))
                ->with('error', 'Could not export PDF. Check logs and try again.');
        }

        $pdf = Pdf::loadView('reports.comparison.asan.pdf', $viewData)->setPaper('a4', 'landscape');

        return $pdf->download('comparison-asan-report.pdf');
    }

    public function exportCsv(ComparisonReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $viewData = $this->buildViewData($request);
        if (($viewData['errorMessage'] ?? null) !== null) {
            return redirect()->to(route('reports.comparison.asan.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        [$headings, $rows] = $this->buildExportRows($viewData);

        return Excel::download(
            new ComparisonReportExport($rows, $headings),
            'comparison-asan-report.csv',
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
            Log::warning('comparison_asan.governorates_unavailable', ['message' => $e->getMessage()]);
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
            Log::error('comparison_asan.report_failed', ['message' => $e->getMessage()]);
            $errorMessage = 'Unable to load Asan comparison report. Check logs and try again.';
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
            'activeComparisonTab' => 'asan',
            ...\App\Support\ReportPdfBranding::viewData($branding),
            'errorMessage' => $errorMessage,
        ];
    }
}
