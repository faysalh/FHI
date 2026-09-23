<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ManufacturingExportsExport;
use App\Exports\ManufacturingPurchasesExport;
use App\Http\Requests\ManufacturingExportStoreRequest;
use App\Http\Requests\ManufacturingExportUpdateRequest;
use App\Http\Requests\ManufacturingIndexRequest;
use App\Http\Requests\ManufacturingItemBulkImportRequest;
use App\Http\Requests\ManufacturingItemStoreRequest;
use App\Http\Requests\ManufacturingItemUpdateRequest;
use App\Http\Requests\ManufacturingPurchaseStoreRequest;
use App\Http\Requests\ManufacturingPurchaseUpdateRequest;
use App\Http\Requests\ManufacturingRangeExportRequest;
use App\Services\ManufacturingSqliteService;
use App\Support\ReportAuthSession;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ManufacturingController extends Controller
{
    public function __construct(
        private readonly ManufacturingSqliteService $manufacturing
    ) {}

    public function index(ManufacturingIndexRequest $request): View
    {
        $filters = $request->filters();
        $tab = $filters['tab'];

        $items = [];
        $stock = [];
        $purchases = [];
        $exports = [];

        try {
            $items = $this->manufacturing->listItems();
            if ($tab === 'stock') {
                $stock = $this->manufacturing->stockBalances();
            } elseif ($tab === 'purchases') {
                $purchases = $this->manufacturing->listPurchases($filters['date_from'], $filters['date_to']);
            } elseif ($tab === 'exports') {
                $exports = $this->manufacturing->listExports($filters['date_from'], $filters['date_to']);
            }
        } catch (Throwable $e) {
            Log::warning('Manufacturing page data unavailable.', ['message' => $e->getMessage()]);
        }

        return view('reports.manufacturing.index', [
            'filters' => $filters,
            'items' => $items,
            'stock' => $stock,
            'purchases' => $purchases,
            'exports' => $exports,
            'canDelete' => ReportAuthSession::canDeleteManufacturing(),
        ]);
    }

    public function storeItem(ManufacturingItemStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->manufacturing->addItem(
                (string) $validated['name'],
                (string) $validated['unit'],
                isset($validated['code']) ? (string) $validated['code'] : null
            );
        } catch (Throwable $e) {
            return $this->redirectTab($request, 'items')->with('error', $e->getMessage());
        }

        return $this->redirectTab($request, 'items')->with('status', 'Item added.');
    }

    public function importItemsCsv(ManufacturingItemBulkImportRequest $request): RedirectResponse
    {
        $updateExisting = $request->wantsUpdateExisting();

        try {
            $bulkLines = trim((string) $request->input('bulk_lines', ''));
            if ($bulkLines !== '') {
                $result = $this->manufacturing->importItemsFromText($bulkLines, $updateExisting);
            } else {
                $file = $request->file('csv_file');
                if ($file === null) {
                    return $this->redirectTab($request, 'items')->with('error', 'Choose a CSV file or paste item lines.');
                }

                $path = $file->getRealPath();
                if ($path === false || $path === '') {
                    $path = $file->getPathname();
                }

                $result = $this->manufacturing->importItemsFromCsv($path, $updateExisting);
            }
        } catch (Throwable $e) {
            return $this->redirectTab($request, 'items')->with('error', $e->getMessage());
        }

        $parts = [];
        if ($result['added'] > 0) {
            $parts[] = $result['added'].' added';
        }
        if ($result['updated'] > 0) {
            $parts[] = $result['updated'].' updated';
        }
        if ($result['skipped_duplicates'] > 0) {
            $parts[] = $result['skipped_duplicates'].' duplicate name'.($result['skipped_duplicates'] === 1 ? '' : 's').' skipped';
        }
        if ($result['skipped_invalid'] > 0) {
            $parts[] = $result['skipped_invalid'].' invalid row'.($result['skipped_invalid'] === 1 ? '' : 's').' skipped';
        }

        $message = 'Bulk import: '.($parts !== [] ? implode(', ', $parts) : 'no changes').'.';

        return $this->redirectTab($request, 'items')->with('status', $message);
    }

    public function updateItem(ManufacturingItemUpdateRequest $request, int $item): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->manufacturing->updateItem(
                $item,
                (string) $validated['name'],
                (string) $validated['unit'],
                isset($validated['code']) ? (string) $validated['code'] : null
            );
        } catch (Throwable $e) {
            return $this->redirectTab($request, 'items')->with('error', $e->getMessage());
        }

        return $this->redirectTab($request, 'items')->with('status', 'Item updated.');
    }

    public function destroyItem(Request $request, int $item): RedirectResponse
    {
        if (! ReportAuthSession::canDeleteManufacturing()) {
            abort(403, 'You do not have permission to delete manufacturing records.');
        }

        try {
            $this->manufacturing->deleteItem($item);
        } catch (Throwable $e) {
            return $this->redirectTab($request, 'items')->with('error', $e->getMessage());
        }

        return $this->redirectTab($request, 'items')->with('status', 'Item deleted.');
    }

    public function storePurchase(ManufacturingPurchaseStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->manufacturing->addPurchase(
                (int) $validated['item_id'],
                (string) $validated['purchase_date'],
                (float) $validated['quantity'],
                (float) $validated['cost_amount'],
                (string) $validated['currency'],
                (string) $validated['supplier_name'],
                (string) ($validated['note'] ?? '')
            );
        } catch (Throwable $e) {
            return $this->redirectPurchases($request)->with('error', $e->getMessage());
        }

        return $this->redirectPurchases($request)->with('status', 'Purchase recorded.');
    }

    public function updatePurchase(ManufacturingPurchaseUpdateRequest $request, int $row): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->manufacturing->updatePurchase(
                $row,
                (int) $validated['item_id'],
                (string) $validated['purchase_date'],
                (float) $validated['quantity'],
                (float) $validated['cost_amount'],
                (string) $validated['currency'],
                (string) $validated['supplier_name'],
                (string) ($validated['note'] ?? '')
            );
        } catch (Throwable $e) {
            return $this->redirectPurchases($request)->with('error', $e->getMessage());
        }

        return $this->redirectPurchases($request)->with('status', 'Purchase updated.');
    }

    public function destroyPurchase(Request $request, int $row): RedirectResponse
    {
        if (! ReportAuthSession::canDeleteManufacturing()) {
            abort(403, 'You do not have permission to delete manufacturing records.');
        }

        try {
            $this->manufacturing->deletePurchase($row);
        } catch (Throwable $e) {
            return $this->redirectPurchases($request)->with('error', $e->getMessage());
        }

        return $this->redirectPurchases($request)->with('status', 'Purchase deleted.');
    }

    public function storeExport(ManufacturingExportStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->manufacturing->addExport(
                (int) $validated['item_id'],
                (string) $validated['export_date'],
                (float) $validated['quantity'],
                (string) ($validated['note'] ?? '')
            );
        } catch (Throwable $e) {
            return $this->redirectExports($request)->with('error', $e->getMessage());
        }

        return $this->redirectExports($request)->with('status', 'Export recorded.');
    }

    public function updateExport(ManufacturingExportUpdateRequest $request, int $row): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->manufacturing->updateExport(
                $row,
                (int) $validated['item_id'],
                (string) $validated['export_date'],
                (float) $validated['quantity'],
                (string) ($validated['note'] ?? '')
            );
        } catch (Throwable $e) {
            return $this->redirectExports($request)->with('error', $e->getMessage());
        }

        return $this->redirectExports($request)->with('status', 'Export updated.');
    }

    public function destroyExport(Request $request, int $row): RedirectResponse
    {
        if (! ReportAuthSession::canDeleteManufacturing()) {
            abort(403, 'You do not have permission to delete manufacturing records.');
        }

        try {
            $this->manufacturing->deleteExport($row);
        } catch (Throwable $e) {
            return $this->redirectExports($request)->with('error', $e->getMessage());
        }

        return $this->redirectExports($request)->with('status', 'Export deleted.');
    }

    public function exportPurchasesPdf(ManufacturingRangeExportRequest $request): Response
    {
        $dateFrom = (string) $request->validated('date_from');
        $dateTo = (string) $request->validated('date_to');
        $rows = $this->manufacturing->listPurchases($dateFrom, $dateTo);

        $pdf = Pdf::loadView('reports.manufacturing.purchases-pdf', array_merge(
            ReportPdfBranding::viewData(),
            [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'rows' => $rows,
            ]
        ));

        return $pdf->download('manufacturing-purchases-'.$dateFrom.'_'.$dateTo.'.pdf');
    }

    public function exportPurchasesCsv(ManufacturingRangeExportRequest $request): BinaryFileResponse
    {
        $dateFrom = (string) $request->validated('date_from');
        $dateTo = (string) $request->validated('date_to');
        $rows = $this->manufacturing->listPurchases($dateFrom, $dateTo);

        return Excel::download(
            new ManufacturingPurchasesExport($rows),
            'manufacturing-purchases-'.$dateFrom.'_'.$dateTo.'.csv',
            Excel::CSV
        );
    }

    public function exportExportsPdf(ManufacturingRangeExportRequest $request): Response
    {
        $dateFrom = (string) $request->validated('date_from');
        $dateTo = (string) $request->validated('date_to');
        $rows = $this->manufacturing->listExports($dateFrom, $dateTo);

        $pdf = Pdf::loadView('reports.manufacturing.exports-pdf', array_merge(
            ReportPdfBranding::viewData(),
            [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'rows' => $rows,
            ]
        ));

        return $pdf->download('manufacturing-exports-'.$dateFrom.'_'.$dateTo.'.pdf');
    }

    public function exportExportsCsv(ManufacturingRangeExportRequest $request): BinaryFileResponse
    {
        $dateFrom = (string) $request->validated('date_from');
        $dateTo = (string) $request->validated('date_to');
        $rows = $this->manufacturing->listExports($dateFrom, $dateTo);

        return Excel::download(
            new ManufacturingExportsExport($rows),
            'manufacturing-exports-'.$dateFrom.'_'.$dateTo.'.csv',
            Excel::CSV
        );
    }

    private function redirectTab(Request $request, string $tab): RedirectResponse
    {
        return redirect()->route('reports.manufacturing.index', array_filter([
            'tab' => $tab,
        ]));
    }

    private function redirectPurchases(Request $request): RedirectResponse
    {
        return redirect()->route('reports.manufacturing.index', array_filter([
            'tab' => 'purchases',
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ], static fn ($v) => $v !== null && $v !== ''));
    }

    private function redirectExports(Request $request): RedirectResponse
    {
        return redirect()->route('reports.manufacturing.index', array_filter([
            'tab' => 'exports',
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ], static fn ($v) => $v !== null && $v !== ''));
    }
}
