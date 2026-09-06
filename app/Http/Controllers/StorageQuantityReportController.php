<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\StorageQuantityReportExport;
use App\Http\Requests\StorageQuantityReportRequest;
use App\Repositories\StorageQuantityReportRepository;
use App\Support\ReportAuthSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class StorageQuantityReportController extends Controller
{
    public function __construct(
        private readonly StorageQuantityReportRepository $repository,
    ) {}

    public function index(StorageQuantityReportRequest $request): View
    {
        return view('reports.storage-quantity.index', $this->buildViewData($request));
    }

    public function exportPdf(StorageQuantityReportRequest $request): Response|RedirectResponse
    {
        try {
            $viewData = $this->buildExportViewData($request);
        } catch (Throwable $e) {
            Log::error('Storage quantity PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.storage-quantity.index', $request->query()))
                ->with('error', 'Could not export PDF.');
        }

        $pdf = Pdf::loadView('reports.storage-quantity.pdf', $viewData)->setPaper('a4', 'landscape');
        $mode = preg_replace('/[^a-z]+/', '-', (string) ($viewData['filters']['balance_mode'] ?? 'normal'));

        return $pdf->download('storage-quantity-'.$mode.'.pdf');
    }

    public function exportCsv(StorageQuantityReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        try {
            $viewData = $this->buildExportViewData($request);
        } catch (Throwable $e) {
            Log::error('Storage quantity CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.storage-quantity.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        $mode = preg_replace('/[^a-z]+/', '-', (string) ($viewData['filters']['balance_mode'] ?? 'normal'));
        $isAdv = ($viewData['filters']['balance_mode'] ?? 'normal') === 'adv';

        return Excel::download(
            new StorageQuantityReportExport($viewData['rows'] ?? [], $isAdv),
            'storage-quantity-'.$mode.'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExportViewData(StorageQuantityReportRequest $request): array
    {
        $viewData = $this->buildViewData($request);
        if (($viewData['errorMessage'] ?? null) !== null) {
            throw new \RuntimeException((string) $viewData['errorMessage']);
        }

        $filters = $this->normalizedFilters($request->validated());
        $rows = $this->repository->exportRows($filters);
        $viewData['rows'] = $rows;
        $viewData['totals'] = $this->repository->totalsFromRows($rows);

        return $viewData;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(StorageQuantityReportRequest $request): array
    {
        $validated = $request->validated();
        $yearOptions = [];
        $storageOptions = [];
        $categoryOptions = [];
        $itemOptions = [];
        $storeTitleOptions = [];
        $rows = null;
        $totals = ['balance_total' => 0.0, 'in_store_total' => 0.0];
        $errorMessage = null;
        $storageAccess = ReportAuthSession::storageAccess();

        $filters = $this->normalizedFilters($validated);

        try {
            $yearOptions = $this->repository->getYearOptions();
            if ($filters['year_id'] === '' && $yearOptions !== []) {
                foreach ($yearOptions as $year) {
                    if ((int) ($year->is_current ?? 0) === 1) {
                        $filters['year_id'] = (string) ($year->year_id ?? '');
                        break;
                    }
                }
                if ($filters['year_id'] === '') {
                    $filters['year_id'] = (string) ($yearOptions[0]->year_id ?? '');
                }
            }

            $storageOptions = $storageAccess->filterStorages($this->repository->getStorageOptions());
            $categoryOptions = $this->repository->getCategoryOptions();
            $itemOptions = $this->repository->getItemOptions($filters['categories']);
            $storeTitleOptions = $this->repository->getStoreTitleOptions();

            if ($filters['year_id'] === '') {
                throw new \RuntimeException('No fiscal year configured.');
            }

            $rows = $this->repository->getReport(
                $filters,
                (int) ($filters['page'] ?? 1),
                (int) ($filters['per_page'] ?? 250)
            );
            $allForTotals = $this->repository->exportRows($filters);
            $totals = $this->repository->totalsFromRows($allForTotals);
        } catch (Throwable $e) {
            Log::error('Storage quantity report failed.', ['message' => $e->getMessage()]);
            $errorMessage = 'Unable to load storage quantity report. Check logs and try again.';
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $filters,
            'yearOptions' => $yearOptions,
            'storeTitleOptions' => $storeTitleOptions,
            'pickerOptions' => $this->buildPickerOptions($storageOptions, $categoryOptions, $itemOptions),
            'storageAccess' => $storageAccess,
            ...\App\Support\ReportPdfBranding::viewData(),
            'errorMessage' => $errorMessage,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizedFilters(array $validated): array
    {
        return [
            'balance_mode' => (string) ($validated['balance_mode'] ?? 'normal'),
            'year_id' => trim((string) ($validated['year_id'] ?? '')),
            'storages' => $this->repository->normalizeStringList($validated['storages'] ?? []),
            'categories' => $this->repository->normalizeStringList($validated['categories'] ?? []),
            'exclude_categories' => $this->repository->normalizeStringList($validated['exclude_categories'] ?? []),
            'items' => $this->repository->normalizeStringList($validated['items'] ?? []),
            'exclude_items' => $this->repository->normalizeStringList($validated['exclude_items'] ?? []),
            'store_title_id' => trim((string) ($validated['store_title_id'] ?? '')),
            'expiration_date' => trim((string) ($validated['expiration_date'] ?? '')),
            'as_of_datetime' => trim((string) ($validated['as_of_datetime'] ?? '')),
            'serial' => trim((string) ($validated['serial'] ?? '')),
            'batch_no' => trim((string) ($validated['batch_no'] ?? '')),
            'hide_negative_balances' => (bool) ($validated['hide_negative_balances'] ?? false),
            'hide_zero_balances' => (bool) ($validated['hide_zero_balances'] ?? false),
            'per_page' => (int) ($validated['per_page'] ?? 250),
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }

    /**
     * @param  list<string>  $storageOptions
     * @param  list<string>  $categoryOptions
     * @param  list<stdClass>  $itemOptions
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function buildPickerOptions(array $storageOptions, array $categoryOptions, array $itemOptions): array
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
        ];
    }
}
