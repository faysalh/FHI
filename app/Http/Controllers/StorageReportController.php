<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\StorageReportExport;
use App\Http\Requests\StorageReportRequest;
use App\Repositories\StorageReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Services\ReportAssemblyPriorityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class StorageReportController extends Controller
{
    public function __construct(
        private readonly StorageReportRepository $repository,
        private readonly CitiesGovernorateSqliteService $governorates,
        private readonly ReportAssemblyPriorityService $assemblyPriority
    ) {}

    public function index(StorageReportRequest $request): View
    {
        return view('reports.storage.index', $this->buildViewData($request));
    }

    public function exportPdf(StorageReportRequest $request): Response|RedirectResponse
    {
        try {
            $viewData = $this->buildExportViewData($request);
        } catch (Throwable $e) {
            Log::error('Storage PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.storage.index', $request->query()))
                ->with('error', 'Could not export PDF.');
        }

        $pdf = Pdf::loadView('reports.storage.pdf', $viewData)->setPaper('a4', 'landscape');

        $safeDate = preg_replace('/[^0-9-]+/', '-', (string) ($viewData['filters']['as_of_date'] ?? ''));

        return $pdf->download('storage-report-'.$safeDate.'.pdf');
    }

    public function exportCsv(StorageReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        try {
            $viewData = $this->buildExportViewData($request);
        } catch (Throwable $e) {
            Log::error('Storage CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.storage.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        $safeDate = preg_replace('/[^0-9-]+/', '-', (string) ($viewData['filters']['as_of_date'] ?? ''));

        $filters = (array) ($viewData['filters'] ?? []);

        return Excel::download(
            new StorageReportExport(
                $viewData['rows'] ?? [],
                (bool) ($filters['show_category'] ?? false),
                (bool) ($filters['show_item_code'] ?? false),
            ),
            'storage-report-'.$safeDate.'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExportViewData(StorageReportRequest $request): array
    {
        $viewData = $this->buildViewData($request);
        if (($viewData['errorMessage'] ?? null) !== null) {
            throw new \RuntimeException((string) $viewData['errorMessage']);
        }

        $filters = (array) ($viewData['filters'] ?? []);
        $storeCities = $this->resolveStoreCitiesFilter($filters);

        $rows = $this->repository->exportRows(
            (string) ($filters['as_of_date'] ?? Carbon::now()->toDateString()),
            $this->repository->normalizeStringList($filters['storages'] ?? []),
            $this->repository->normalizeStringList($filters['categories'] ?? []),
            $this->repository->normalizeStringList($filters['exclude_categories'] ?? []),
            $this->repository->normalizeStringList($filters['items'] ?? []),
            $this->repository->normalizeStringList($filters['exclude_items'] ?? []),
            $storeCities
        );
        $rows = $this->assemblyPriority->sortRows($rows, 'category_name', 'item_name');

        $totals = ['quantity_total' => 0.0, 'weight_total' => 0.0];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $totals['quantity_total'] += (float) ($row->quantity_total ?? 0);
                $totals['weight_total'] += (float) ($row->weight_total ?? 0);
            }
        }

        $viewData['rows'] = $rows;
        $viewData['totals'] = $totals;
        $viewData['categoryTotals'] = $this->mapCategoryTotals(
            $this->repository->getCategoryTotals(
                (string) ($filters['as_of_date'] ?? Carbon::now()->toDateString()),
                $this->repository->normalizeStringList($filters['storages'] ?? []),
                $this->repository->normalizeStringList($filters['categories'] ?? []),
                $this->repository->normalizeStringList($filters['exclude_categories'] ?? []),
                $this->repository->normalizeStringList($filters['items'] ?? []),
                $this->repository->normalizeStringList($filters['exclude_items'] ?? []),
                $storeCities
            )
        );

        return $viewData;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return array<string, array{quantity_total: float, weight_total: float}>
     */
    private function mapCategoryTotals(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->category_name ?? ''));
            if ($name === '') {
                $name = '(uncategorized)';
            }
            $map[$name] = [
                'quantity_total' => (float) ($row->quantity_total ?? 0),
                'weight_total' => (float) ($row->weight_total ?? 0),
            ];
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function resolveStoreCitiesFilter(array $filters): array
    {
        $cities = $this->repository->normalizeStringList($filters['cities'] ?? []);
        $savedGovernorateId = (int) ($filters['saved_governorate_id'] ?? 0);
        $governorateCities = [];
        if ($savedGovernorateId > 0) {
            try {
                $selectedGov = $this->governorates->getGovernorateById($savedGovernorateId);
                if ($selectedGov !== null) {
                    $govCity = trim((string) ($selectedGov['governorate_city'] ?? ''));
                    if ($govCity !== '') {
                        $governorateCities[] = $govCity;
                    }
                    $governorateCities = array_values(array_unique(array_merge(
                        $governorateCities,
                        (array) ($selectedGov['members'] ?? [])
                    )));
                }
            } catch (Throwable $e) {
                Log::warning('storage.export_governorate_unavailable', ['message' => $e->getMessage()]);
            }
        }

        return $this->repository->normalizeStringList(array_values(array_unique(array_merge($cities, $governorateCities))));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(StorageReportRequest $request): array
    {
        $today = Carbon::now()->toDateString();
        $input = array_merge([
            'as_of_date' => $today,
            'per_page' => 250,
            'storages' => [],
            'categories' => [],
            'exclude_categories' => [],
            'items' => [],
            'exclude_items' => [],
            'cities' => [],
            'saved_governorate_id' => 0,
            'show_category' => false,
            'show_item_code' => false,
        ], $request->validated());

        $asOfDate = (string) $input['as_of_date'];
        $perPage = (int) ($input['per_page'] ?? 250);
        $page = (int) ($input['page'] ?? 1);
        /** @var list<string> $storages */
        $storages = $this->repository->normalizeStringList($input['storages'] ?? []);
        /** @var list<string> $categories */
        $categories = $this->repository->normalizeStringList($input['categories'] ?? []);
        /** @var list<string> $excludeCategories */
        $excludeCategories = $this->repository->normalizeStringList($input['exclude_categories'] ?? []);
        /** @var list<string> $items */
        $items = $this->repository->normalizeStringList($input['items'] ?? []);
        /** @var list<string> $excludeItems */
        $excludeItems = $this->repository->normalizeStringList($input['exclude_items'] ?? []);
        /** @var list<string> $cities */
        $cities = $this->repository->normalizeStringList($input['cities'] ?? []);
        $savedGovernorateId = (int) ($input['saved_governorate_id'] ?? 0);

        $savedGovernorates = [];
        $governorateLabel = 'None';
        $governorateCities = [];
        try {
            $savedGovernorates = $this->governorates->listGovernorates();
            if ($savedGovernorateId > 0) {
                $selectedGov = $this->governorates->getGovernorateById($savedGovernorateId);
                if ($selectedGov !== null) {
                    $governorateLabel = (string) ($selectedGov['name'] ?? 'None');
                    $govCity = trim((string) ($selectedGov['governorate_city'] ?? ''));
                    if ($govCity !== '') {
                        $governorateCities[] = $govCity;
                    }
                    $governorateCities = array_values(array_unique(array_merge(
                        $governorateCities,
                        (array) ($selectedGov['members'] ?? [])
                    )));
                }
            }
        } catch (Throwable $e) {
            Log::warning('storage.governorates_unavailable', ['message' => $e->getMessage()]);
        }

        $storeCities = array_values(array_unique(array_merge($cities, $governorateCities)));
        $storeCities = $this->repository->normalizeStringList($storeCities);

        $filters = [
            'as_of_date' => $asOfDate,
            'per_page' => $perPage,
            'storages' => $storages,
            'categories' => $categories,
            'exclude_categories' => $excludeCategories,
            'items' => $items,
            'exclude_items' => $excludeItems,
            'cities' => $cities,
            'saved_governorate_id' => $savedGovernorateId > 0 ? $savedGovernorateId : '',
            'show_category' => (bool) ($input['show_category'] ?? false),
            'show_item_code' => (bool) ($input['show_item_code'] ?? false),
        ];

        $storageOptions = [];
        $categoryOptions = [];
        $itemOptions = [];
        $storeCityOptions = [];
        $hasStoreCityColumn = false;
        $rows = null;
        $totals = ['quantity_total' => 0.0, 'weight_total' => 0.0];
        /** @var array<string, array{quantity_total: float, weight_total: float}> $categoryTotals */
        $categoryTotals = [];
        $errorMessage = null;

        try {
            $storageOptions = $this->repository->getStorageOptions();
            $categoryOptions = $this->repository->getCategoryOptions();
            $itemOptions = $this->repository->getItemOptions($categories);
            $storeCityOptions = $this->repository->getStoreCityOptions();
            $hasStoreCityColumn = $this->repository->hasStoreCityColumn();

            $rows = $this->repository->getReport(
                $asOfDate,
                $storages,
                $categories,
                $excludeCategories,
                $items,
                $excludeItems,
                $storeCities,
                $page,
                $perPage
            );
            $sorted = $this->assemblyPriority->sortRows($rows->items(), 'category_name', 'item_name');
            $rows->setCollection(collect($sorted));

            $totals = $this->repository->getReportTotals(
                $asOfDate,
                $storages,
                $categories,
                $excludeCategories,
                $items,
                $excludeItems,
                $storeCities
            );
            $categoryTotals = $this->mapCategoryTotals(
                $this->repository->getCategoryTotals(
                    $asOfDate,
                    $storages,
                    $categories,
                    $excludeCategories,
                    $items,
                    $excludeItems,
                    $storeCities
                )
            );
        } catch (Throwable $e) {
            Log::error('Storage report failed.', [
                'as_of_date' => $asOfDate,
                'message' => $e->getMessage(),
            ]);
            $errorMessage = 'Unable to load storage report. Check logs and try again.';
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'categoryTotals' => $categoryTotals,
            'filters' => $filters,
            'pickerOptions' => $this->buildPickerOptions($storageOptions, $categoryOptions, $itemOptions, $storeCityOptions),
            'hasStoreCityColumn' => $hasStoreCityColumn,
            'savedGovernorates' => $savedGovernorates,
            'governorateLabel' => $governorateLabel,
            ...\App\Support\ReportPdfBranding::viewData(),
            'errorMessage' => $errorMessage,
        ];
    }

    /**
     * @param  list<string>  $storageOptions
     * @param  list<string>  $categoryOptions
     * @param  list<stdClass>  $itemOptions
     * @param  list<string>  $storeCityOptions
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function buildPickerOptions(array $storageOptions, array $categoryOptions, array $itemOptions, array $storeCityOptions): array
    {
        $mapSimple = static function (array $values): array {
            $out = [];
            foreach ($values as $value) {
                $id = trim((string) $value);
                if ($id === '') {
                    continue;
                }
                $out[] = ['id' => $id, 'name' => $id];
            }

            return $out;
        };

        $items = [];
        foreach ($itemOptions as $opt) {
            $id = trim((string) ($opt->item_id ?? ''));
            if ($id === '') {
                continue;
            }
            $category = trim((string) ($opt->category_name ?? ''));
            $name = trim((string) ($opt->item_name ?? ''));
            $label = $category !== '' && $name !== ''
                ? $category.' — '.$name
                : ($name !== '' ? $name : $id);
            $items[] = ['id' => $id, 'name' => $label];
        }

        return [
            'storages' => $mapSimple($storageOptions),
            'categories' => $mapSimple($categoryOptions),
            'exclude_categories' => $mapSimple($categoryOptions),
            'items' => $items,
            'exclude_items' => $items,
            'cities' => $mapSimple($storeCityOptions),
        ];
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
            Log::warning('storage_report_logo_data_uri_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
