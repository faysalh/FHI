<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SalesByItemReportExport;
use App\Http\Requests\SalesByItemReportRequest;
use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Repositories\SalesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\ReportAssemblyPriorityService;
use App\Support\SalesItemPriceTiers;
use App\Support\SalesItemReportMetrics;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SalesByItemReportController extends Controller
{
    private const EXPORT_ROW_CAP = 10000;

    public function __construct(
        private readonly SalesByItemReportRepository $repository,
        private readonly SalesReportRepository $salesRepository,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly SalesBySalesmanReportRepository $salesBySalesman,
        private readonly ReportAssemblyPriorityService $assemblyPriority
    ) {}

    public function index(SalesByItemReportRequest $request): View
    {
        $today = Carbon::now()->toDateString();
        $input = array_merge([
            'date_from' => $today,
            'date_to' => $today,
            'per_page' => 250,
            'salesman_id' => '',
            'storage' => '',
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $perPage = (int) ($input['per_page'] ?? 250);
        $page = (int) ($input['page'] ?? 1);
        $salesmanIdRaw = trim((string) ($input['salesman_id'] ?? ''));
        $salesmanId = $this->repository->normalizeSalesmanId($salesmanIdRaw);
        $storage = trim((string) ($input['storage'] ?? ''));
        $cities = $this->salesRepository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $categories = $this->repository->normalizeCategories(
            is_array($input['categories'] ?? null) ? $input['categories'] : []
        );
        $priceTiers = $this->repository->normalizePriceTiers(
            is_array($input['price_tiers'] ?? null) ? $input['price_tiers'] : []
        );
        if (count($priceTiers) === count(SalesItemPriceTiers::LABELS)) {
            $priceTiers = [];
        }
        $activePriceTiers = SalesItemPriceTiers::activeDefinitions($priceTiers);
        $showUnknownColumn = $priceTiers === [];
        ['metrics' => $metrics, 'activeMetrics' => $activeMetrics] = $this->resolveDisplayMetrics($input);

        $filters = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'per_page' => $perPage,
            'salesman_id' => $salesmanIdRaw,
            'storage' => $storage,
            'cities' => $cities,
            'categories' => $categories,
            'price_tiers' => $priceTiers,
            'metrics' => $metrics,
        ];

        $salesmen = $this->visitsRepository->getSalesmanOptions();
        $storageOptions = $this->salesRepository->getStorageOptions();
        $cityOptions = $this->cityOptionsForPicker();
        $hasCityColumn = $this->visitsRepository->getAccountCityColumnName() !== null;
        $salesmanName = $this->resolveSalesmanName($salesmen, $salesmanId);
        $priceGroupColumn = null;
        try {
            $priceGroupColumn = $this->salesBySalesman->getResolvedClientPriceGroupColumn();
        } catch (Throwable $e) {
            Log::warning('sales_by_item.price_group_column_probe_failed', ['message' => $e->getMessage()]);
        }
        $categoryOptions = [];

        if ($salesmanId !== null) {
            try {
                $categoryOptions = $this->repository->getCategoryOptions(
                    $dateFrom,
                    $dateTo,
                    $salesmanId,
                    $cities,
                    $storage !== '' ? $storage : null,
                    $categories,
                    $priceTiers
                );
            } catch (Throwable $e) {
                Log::warning('Sales by item category options failed.', ['message' => $e->getMessage()]);
            }
        }

        if ($salesmanId === null) {
            return view('reports.sales-by-item.index', [
                'rows' => null,
                'grandTotals' => null,
                'filters' => $filters,
                'salesmen' => $salesmen,
                'salesmanName' => $salesmanName,
                'storageOptions' => $storageOptions,
                'cityOptions' => $cityOptions,
                'categoryOptions' => $categoryOptions,
                'hasCityColumn' => $hasCityColumn,
                'priceTiers' => SalesItemPriceTiers::definitions(),
                'activePriceTiers' => $activePriceTiers,
                'showUnknownColumn' => $showUnknownColumn,
                'activeMetrics' => $activeMetrics,
                'priceGroupColumn' => $priceGroupColumn,
                'errorMessage' => null,
                'needsSalesman' => true,
            ]);
        }

        try {
            $rows = $this->repository->getReport(
                $dateFrom,
                $dateTo,
                $salesmanId,
                $cities,
                $storage !== '' ? $storage : null,
                $categories,
                $page,
                $perPage,
                $priceTiers
            );
            $rows->setCollection(collect(
                $this->assemblyPriority->sortRows($rows->items(), 'category_name', 'category_name')
            ));
            $grandTotals = $this->repository->getGrandTotals(
                $dateFrom,
                $dateTo,
                $salesmanId,
                $cities,
                $storage !== '' ? $storage : null,
                $categories,
                $priceTiers
            );
        } catch (Throwable $e) {
            Log::error('Sales by item report failed.', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'salesman_id' => $salesmanId,
                'message' => $e->getMessage(),
            ]);

            return view('reports.sales-by-item.index', [
                'rows' => null,
                'grandTotals' => null,
                'filters' => $filters,
                'salesmen' => $salesmen,
                'salesmanName' => $salesmanName,
                'storageOptions' => $storageOptions,
                'cityOptions' => $cityOptions,
                'categoryOptions' => $categoryOptions,
                'hasCityColumn' => $hasCityColumn,
                'priceTiers' => SalesItemPriceTiers::definitions(),
                'activePriceTiers' => $activePriceTiers,
                'showUnknownColumn' => $showUnknownColumn,
                'activeMetrics' => $activeMetrics,
                'priceGroupColumn' => $priceGroupColumn,
                'errorMessage' => 'Unable to load report. Check logs and try again.',
                'needsSalesman' => false,
            ]);
        }

        return view('reports.sales-by-item.index', [
            'rows' => $rows,
            'grandTotals' => $grandTotals,
            'filters' => $filters,
            'salesmen' => $salesmen,
            'salesmanName' => $salesmanName,
            'storageOptions' => $storageOptions,
            'cityOptions' => $cityOptions,
            'categoryOptions' => $categoryOptions,
            'hasCityColumn' => $hasCityColumn,
            'priceTiers' => SalesItemPriceTiers::definitions(),
            'activePriceTiers' => $activePriceTiers,
            'showUnknownColumn' => $showUnknownColumn,
            'activeMetrics' => $activeMetrics,
            'priceGroupColumn' => $priceGroupColumn,
            'errorMessage' => null,
            'needsSalesman' => false,
        ]);
    }

    public function exportPdf(SalesByItemReportRequest $request): Response|RedirectResponse
    {
        $context = $this->exportContext($request);
        if ($context instanceof RedirectResponse) {
            return $context;
        }

        try {
            $rows = $this->repository->exportRows(
                $context['dateFrom'],
                $context['dateTo'],
                $context['salesmanId'],
                $context['cities'],
                $context['storage'],
                $context['categories'],
                $context['priceTiers']
            );
            $rows = $this->assemblyPriority->sortRows($rows, 'category_name', 'category_name');
            $grandTotals = $this->repository->getGrandTotals(
                $context['dateFrom'],
                $context['dateTo'],
                $context['salesmanId'],
                $context['cities'],
                $context['storage'],
                $context['categories'],
                $context['priceTiers']
            );
        } catch (Throwable $e) {
            Log::error('Sales by item PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales-by-item.index', $request->query()))
                ->with('error', 'Could not export PDF.');
        }

        if (count($rows) >= self::EXPORT_ROW_CAP) {
            return redirect()
                ->to(route('reports.sales-by-item.index', $request->query()))
                ->with('error', 'Export hit the row limit ('.self::EXPORT_ROW_CAP.'). Narrow filters.');
        }

        $pdf = Pdf::loadView('reports.sales-by-item.pdf', [
            'rows' => $rows,
            'grandTotals' => $grandTotals,
            'dateFrom' => $context['dateFrom'],
            'dateTo' => $context['dateTo'],
            'salesmanName' => $context['salesmanName'],
            'storage' => $context['storageLabel'],
            'cities' => $context['cities'],
            'categories' => $context['categories'],
            'priceTiers' => $context['activePriceTiers'],
            'showUnknownColumn' => $context['showUnknownColumn'],
            'activeMetrics' => $context['activeMetrics'],
            'priceTierFilterLabels' => $context['priceTierFilterLabels'],
            'metricFilterLabels' => $context['metricFilterLabels'],
            ...\App\Support\ReportPdfBranding::viewData(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('sales-by-item-'.$context['dateFrom'].'-'.$context['dateTo'].'.pdf');
    }

    public function exportCsv(SalesByItemReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $context = $this->exportContext($request);
        if ($context instanceof RedirectResponse) {
            return $context;
        }

        try {
            $rows = $this->repository->exportRows(
                $context['dateFrom'],
                $context['dateTo'],
                $context['salesmanId'],
                $context['cities'],
                $context['storage'],
                $context['categories'],
                $context['priceTiers']
            );
            $rows = $this->assemblyPriority->sortRows($rows, 'category_name', 'category_name');
            $grandTotals = $this->repository->getGrandTotals(
                $context['dateFrom'],
                $context['dateTo'],
                $context['salesmanId'],
                $context['cities'],
                $context['storage'],
                $context['categories'],
                $context['priceTiers']
            );
        } catch (Throwable $e) {
            Log::error('Sales by item CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales-by-item.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        if (count($rows) >= self::EXPORT_ROW_CAP) {
            return redirect()
                ->to(route('reports.sales-by-item.index', $request->query()))
                ->with('error', 'Export hit the row limit ('.self::EXPORT_ROW_CAP.'). Narrow filters.');
        }

        return Excel::download(
            new SalesByItemReportExport(
                $rows,
                $grandTotals,
                $context['activePriceTiers'],
                $context['showUnknownColumn'],
                $context['activeMetrics']
            ),
            'sales-by-item-'.$context['dateFrom'].'-'.$context['dateTo'].'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @param  list<array{id: string, name: string}>  $salesmen
     */
    private function resolveSalesmanName(array $salesmen, ?string $salesmanId): string
    {
        if ($salesmanId === null) {
            return '';
        }
        foreach ($salesmen as $sm) {
            if (($sm['id'] ?? '') === $salesmanId) {
                return (string) ($sm['name'] ?? '');
            }
        }

        return '';
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function cityOptionsForPicker(): array
    {
        $out = [];
        foreach ($this->visitsRepository->getCityOptions() as $city) {
            if ($city !== '') {
                $out[] = ['id' => $city, 'name' => $city];
            }
        }

        return $out;
    }

    /**
     * @return array{
     *     dateFrom: string,
     *     dateTo: string,
     *     salesmanId: string,
     *     salesmanName: string,
     *     storage: string|null,
     *     storageLabel: string,
     *     cities: list<string>,
     *     categories: list<string>,
     *     priceTiers: list<int>,
     *     activePriceTiers: list<array{tier: int, label: string}>,
     *     showUnknownColumn: bool,
     *     priceTierFilterLabels: list<string>
     * }|RedirectResponse
     */
    private function exportContext(SalesByItemReportRequest $request): array|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $salesmanId = $this->repository->normalizeSalesmanId(trim((string) ($input['salesman_id'] ?? '')));

        if ($salesmanId === null) {
            return redirect()
                ->to(route('reports.sales-by-item.index', $request->query()))
                ->with('error', 'Choose a salesman before exporting.');
        }

        $storage = trim((string) ($input['storage'] ?? ''));
        $cities = $this->salesRepository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $categories = $this->repository->normalizeCategories(
            is_array($input['categories'] ?? null) ? $input['categories'] : []
        );
        $priceTiers = $this->repository->normalizePriceTiers(
            is_array($input['price_tiers'] ?? null) ? $input['price_tiers'] : []
        );
        if (count($priceTiers) === count(SalesItemPriceTiers::LABELS)) {
            $priceTiers = [];
        }
        $activePriceTiers = SalesItemPriceTiers::activeDefinitions($priceTiers);
        $showUnknownColumn = $priceTiers === [];
        ['metrics' => $metrics, 'activeMetrics' => $activeMetrics] = $this->resolveDisplayMetrics($input);
        $salesmen = $this->visitsRepository->getSalesmanOptions();

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'salesmanId' => $salesmanId,
            'salesmanName' => $this->resolveSalesmanName($salesmen, $salesmanId),
            'storage' => $storage !== '' ? $storage : null,
            'storageLabel' => $storage,
            'cities' => $cities,
            'categories' => $categories,
            'priceTiers' => $priceTiers,
            'metrics' => $metrics,
            'activePriceTiers' => $activePriceTiers,
            'showUnknownColumn' => $showUnknownColumn,
            'activeMetrics' => $activeMetrics,
            'priceTierFilterLabels' => $priceTiers !== []
                ? array_map(
                    static fn (array $tier): string => 'Price '.$tier['tier'].' ('.$tier['label'].')',
                    $activePriceTiers
                )
                : [],
            'metricFilterLabels' => $metrics !== []
                ? array_map(static fn (array $m): string => $m['label'], $activeMetrics)
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{metrics: list<string>, activeMetrics: list<array{key: string, label: string, suffix: string}>}
     */
    private function resolveDisplayMetrics(array $input): array
    {
        $metrics = SalesItemReportMetrics::normalize(
            is_array($input['metrics'] ?? null) ? $input['metrics'] : []
        );
        if (count($metrics) === count(SalesItemReportMetrics::ALL)) {
            $metrics = [];
        }

        return [
            'metrics' => $metrics,
            'activeMetrics' => SalesItemReportMetrics::activeDefinitions($metrics),
        ];
    }
}
