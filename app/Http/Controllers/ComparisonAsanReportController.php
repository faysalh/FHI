<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ComparisonReportExport;
use App\Http\Requests\ComparisonAsanReportRequest;
use App\Repositories\ComparisonAsanReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Services\ReportAssemblyPriorityService;
use App\Support\ComparisonMonthRows;
use App\Support\ComparisonMonthSpan;
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
    public function __construct(
        private readonly ComparisonAsanReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly CitiesGovernorateSqliteService $governorates,
        private readonly ReportAssemblyPriorityService $assemblyPriorityService
    ) {}

    public function index(ComparisonAsanReportRequest $request): View
    {
        $viewData = $this->buildViewData($request);

        return view('reports.comparison.asan.index', $viewData);
    }

    public function exportPdf(ComparisonAsanReportRequest $request): Response|RedirectResponse
    {
        $viewData = $this->buildViewData($request);
        if (($viewData['errorMessage'] ?? null) !== null) {
            return redirect()->to(route('reports.comparison.asan.index', $request->query()))
                ->with('error', 'Could not export PDF. Check logs and try again.');
        }

        $pdf = Pdf::loadView('reports.comparison.asan.pdf', $viewData)->setPaper('a4', 'landscape');

        return $pdf->download('comparison-asan-report.pdf');
    }

    public function exportCsv(ComparisonAsanReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $viewData = $this->buildViewData($request);
        if (($viewData['errorMessage'] ?? null) !== null) {
            return redirect()->to(route('reports.comparison.asan.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        [$headings, $rows] = ComparisonMonthRows::export(
            $viewData['months'],
            $viewData['groupedRows'],
            $viewData['totals'],
            $viewData['filters']['metrics']
        );

        return Excel::download(
            new ComparisonReportExport($rows, $headings),
            'comparison-asan-report.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(ComparisonAsanReportRequest $request): array
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $months = ComparisonMonthSpan::between($dateFrom, $dateTo);
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
            $rowsByMonth = [];
            foreach ($months as $month) {
                $rowsByMonth[$month['key']] = $this->repository->getItemRows(
                    $month['from'],
                    $month['to'],
                    $citiesFilter,
                    $salesmanId !== '' ? $salesmanId : null,
                    $effectiveExclude
                );
            }
            $comparisonRows = ComparisonMonthRows::merge($months, $rowsByMonth);
            $comparisonRows = $this->assemblyPriorityService->sortRows($comparisonRows, 'category_name', 'item_name');
            $categoryOptions = $this->repository->getCategoryOptions(
                $dateFrom,
                $dateTo,
                $citiesFilter,
                $salesmanId !== '' ? $salesmanId : null
            );
        } catch (Throwable $e) {
            Log::error('comparison_asan.report_failed', ['message' => $e->getMessage()]);
            $errorMessage = 'Unable to load Asan comparison report. Check logs and try again.';
        }

        $groupedRows = $comparisonRows === [] ? [] : ComparisonMonthRows::grouped($comparisonRows, $months);
        $totals = $comparisonRows === [] ? [] : ComparisonMonthRows::totals($comparisonRows, $months);

        return [
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'salesman_id' => $salesmanId,
                'city' => $city,
                'saved_governorate_id' => $savedGovernorateId > 0 ? $savedGovernorateId : '',
                'metrics' => $metrics,
                'exclude_category' => $excludeCategory,
            ],
            'months' => $months,
            'monthSummary' => ComparisonMonthSpan::summary($months),
            'changeLabel' => ComparisonMonthSpan::changeLabel($months),
            'salesmanLabel' => $salesmanLabel,
            'governorateLabel' => $governorateLabel,
            'cityOptions' => $cityOptions,
            'salesmanOptions' => $salesmanOptions,
            'savedGovernorates' => $savedGovernorates,
            'categoryOptions' => $categoryOptions,
            'rows' => $comparisonRows,
            'groupedRows' => $groupedRows,
            'totals' => $totals,
            'activeComparisonTab' => 'asan',
            ...\App\Support\ReportPdfBranding::viewData($branding),
            'errorMessage' => $errorMessage,
        ];
    }
}
