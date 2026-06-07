<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\StorageItemsReportExport;
use App\Http\Requests\StorageItemsReportRequest;
use App\Repositories\StorageItemsReportRepository;
use App\Services\ReportAssemblyPriorityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class StorageItemsReportController extends Controller
{
    public function __construct(
        private readonly StorageItemsReportRepository $repository,
        private readonly ReportAssemblyPriorityService $assemblyPriority
    ) {}

    public function index(StorageItemsReportRequest $request): View
    {
        return view('reports.storage-items.index', $this->resolveStorageItemsReportPayload($request));
    }

    public function evaluation(StorageItemsReportRequest $request): View
    {
        return view('reports.storage-items.evaluation', $this->resolveStorageItemsReportPayload($request));
    }

    /**
     * @return array{
     *     rows: LengthAwarePaginator|null,
     *     filters: array<string, mixed>,
     *     storageOptions: list<string>,
     *     categoryOptions: list<string>,
     *     errorMessage: string|null
     * }
     */
    private function resolveStorageItemsReportPayload(StorageItemsReportRequest $request): array
    {
        $today = Carbon::now()->toDateString();
        $input = array_merge([
            'as_of_date' => $today,
            'sales_date_from' => $today,
            'sales_date_to' => $today,
            'working_days' => 1,
            'per_page' => 250,
            'storage' => '',
            'category' => '',
            'exclude_categories' => [],
            'item' => '',
        ], $request->validated());

        $asOfDate = (string) $input['as_of_date'];
        $salesDateFrom = (string) $input['sales_date_from'];
        $salesDateTo = (string) $input['sales_date_to'];
        $workingDays = max(1, min(366, (int) $input['working_days']));
        $perPage = (int) ($input['per_page'] ?? 250);
        $page = (int) ($input['page'] ?? 1);
        $storage = trim((string) ($input['storage'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        /** @var list<string> $excludeCategories */
        $excludeCategories = array_values(array_unique(array_filter(
            array_map(static fn ($v): string => trim((string) $v), (array) ($input['exclude_categories'] ?? [])),
            static fn (string $s): bool => $s !== ''
        )));
        $item = trim((string) ($input['item'] ?? ''));
        $storageOptions = [];
        $categoryOptions = [];

        $filters = [
            'as_of_date' => $asOfDate,
            'sales_date_from' => $salesDateFrom,
            'sales_date_to' => $salesDateTo,
            'working_days' => $workingDays,
            'per_page' => $perPage,
            'storage' => $storage,
            'category' => $category,
            'exclude_categories' => $excludeCategories,
            'item' => $item,
        ];

        try {
            $storageOptions = $this->repository->getStorageOptions();
            $categoryOptions = $this->repository->getCategoryOptions($asOfDate);
            $rows = $this->repository->getEvaluationReport(
                $asOfDate,
                $storage !== '' ? $storage : null,
                $category !== '' ? $category : null,
                $excludeCategories,
                $item !== '' ? $item : null,
                $salesDateFrom,
                $salesDateTo,
                $page,
                $perPage
            );
            $evaluationTotals = $this->repository->getEvaluationTotals(
                $asOfDate,
                $storage !== '' ? $storage : null,
                $category !== '' ? $category : null,
                $excludeCategories,
                $item !== '' ? $item : null,
                $salesDateFrom,
                $salesDateTo
            );
        } catch (Throwable $e) {
            Log::error('Storage items report failed.', [
                'as_of_date' => $asOfDate,
                'message' => $e->getMessage(),
            ]);

            return [
                'rows' => null,
                'evaluationTotals' => null,
                'filters' => $filters,
                'storageOptions' => $storageOptions,
                'categoryOptions' => $categoryOptions,
                'errorMessage' => 'Unable to load storage items report. Check logs and try again.',
            ];
        }

        $sorted = $this->assemblyPriority->sortRows($rows->items(), 'category_name', 'item_name');
        foreach ($sorted as $row) {
            if ($row instanceof stdClass) {
                $this->decorateStockSalesMetrics($row, $workingDays);
            }
        }
        $rows->setCollection(collect($sorted));

        return [
            'rows' => $rows,
            'evaluationTotals' => $evaluationTotals ?? null,
            'filters' => $filters,
            'storageOptions' => $storageOptions,
            'categoryOptions' => $categoryOptions,
            'errorMessage' => null,
        ];
    }

    public function exportPdf(StorageItemsReportRequest $request): Response|RedirectResponse
    {
        $input = $request->validated();
        $asOfDate = (string) $input['as_of_date'];
        $salesDateFrom = (string) $input['sales_date_from'];
        $salesDateTo = (string) $input['sales_date_to'];
        $workingDays = max(1, min(366, (int) $input['working_days']));
        $category = trim((string) ($input['category'] ?? ''));
        /** @var list<string> $excludeCategories */
        $excludeCategories = array_values(array_unique(array_filter(
            array_map(static fn ($v): string => trim((string) $v), (array) ($input['exclude_categories'] ?? [])),
            static fn (string $s): bool => $s !== ''
        )));
        $item = trim((string) ($input['item'] ?? ''));
        $storage = trim((string) ($input['storage'] ?? ''));

        try {
            $rows = $this->repository->exportEvaluationRows(
                $asOfDate,
                $storage !== '' ? $storage : null,
                $category !== '' ? $category : null,
                $excludeCategories,
                $item !== '' ? $item : null,
                $salesDateFrom,
                $salesDateTo
            );
            $rows = $this->assemblyPriority->sortRows($rows, 'category_name', 'item_name');
            foreach ($rows as $row) {
                if ($row instanceof stdClass) {
                    $this->decorateStockSalesMetrics($row, $workingDays);
                }
            }
        } catch (Throwable $e) {
            Log::error('Storage items PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.storage-items.index', $request->query()))
                ->with('error', 'Could not export PDF.');
        }

        $branding = InvoiceBrandingSettingsController::getSettings();

        $safeFrom = preg_replace('/[^0-9-]+/', '-', $salesDateFrom);
        $safeTo = preg_replace('/[^0-9-]+/', '-', $salesDateTo);

        $pdf = Pdf::loadView('reports.storage-items.pdf', [
            'rows' => $rows,
            'asOfDate' => $asOfDate,
            'salesDateFrom' => $salesDateFrom,
            'salesDateTo' => $salesDateTo,
            'workingDays' => $workingDays,
            'category' => $category,
            'excludeCategories' => $excludeCategories,
            'item' => $item,
            'storage' => $storage,
            ...\App\Support\ReportPdfBranding::viewData($branding),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('storage-items-'.$asOfDate.'_sales-'.$safeFrom.'_'.$safeTo.'.pdf');
    }

    public function exportCsv(StorageItemsReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = $request->validated();
        $asOfDate = (string) $input['as_of_date'];
        $salesDateFrom = (string) $input['sales_date_from'];
        $salesDateTo = (string) $input['sales_date_to'];
        $workingDays = max(1, min(366, (int) $input['working_days']));
        $category = trim((string) ($input['category'] ?? ''));
        /** @var list<string> $excludeCategories */
        $excludeCategories = array_values(array_unique(array_filter(
            array_map(static fn ($v): string => trim((string) $v), (array) ($input['exclude_categories'] ?? [])),
            static fn (string $s): bool => $s !== ''
        )));
        $item = trim((string) ($input['item'] ?? ''));
        $storage = trim((string) ($input['storage'] ?? ''));

        try {
            $rows = $this->repository->exportEvaluationRows(
                $asOfDate,
                $storage !== '' ? $storage : null,
                $category !== '' ? $category : null,
                $excludeCategories,
                $item !== '' ? $item : null,
                $salesDateFrom,
                $salesDateTo
            );
            $rows = $this->assemblyPriority->sortRows($rows, 'category_name', 'item_name');
            foreach ($rows as $row) {
                if ($row instanceof stdClass) {
                    $this->decorateStockSalesMetrics($row, $workingDays);
                }
            }
        } catch (Throwable $e) {
            Log::error('Storage items CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.storage-items.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        $safeFrom = preg_replace('/[^0-9-]+/', '-', $salesDateFrom);
        $safeTo = preg_replace('/[^0-9-]+/', '-', $salesDateTo);

        return Excel::download(
            new StorageItemsReportExport($rows, $workingDays),
            'storage-items-'.$asOfDate.'_sales-'.$safeFrom.'_'.$safeTo.'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    private function decorateStockSalesMetrics(stdClass $row, int $workingDays): void
    {
        $wd = max(1, $workingDays);
        $sold = (float) ($row->sold_quantity_period ?? 0);
        $qty = (float) ($row->quantity_total ?? 0);
        $avg = $sold / $wd;
        $row->avg_sold_per_working_day = $avg;
        $row->storage_working_days_cover = ($sold > 0 && $qty >= 0) ? ($qty / $avg) : null;
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
            Log::warning('storage_items_logo_data_uri_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
