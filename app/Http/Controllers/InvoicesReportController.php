<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\InvoicesReportExport;
use App\Http\Requests\InvoicesReportRequest;
use App\Repositories\InvoicesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\ReportCityPickerOptions;
use App\Services\ReportAssemblyPriorityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

class InvoicesReportController extends Controller
{
    private const SELECTION_CACHE_KEY = 'reports.invoices.selection.v1';

    public function __construct(
        private readonly InvoicesReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly ReportAssemblyPriorityService $assemblyPriority
    ) {}

    public function index(InvoicesReportRequest $request): View
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $perPage = (int) ($input['per_page'] ?? 250);
        $page = (int) ($input['page'] ?? 1);
        $store = trim((string) ($input['store'] ?? ''));
        $salesmanId = trim((string) ($input['salesman_id'] ?? ''));
        $q = trim((string) ($input['q'] ?? ''));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        $storeOptions = $this->repository->getStoreOptions();
        $cityOptions = $this->visitsRepository->getCityOptions();
        $salesmen = $this->visitsRepository->getSalesmanOptions();

        $grandTotals = null;
        try {
            $rows = $this->repository->getReport(
                $dateFrom,
                $dateTo,
                $cities,
                $store !== '' ? $store : null,
                $salesmanId !== '' ? $salesmanId : null,
                $q !== '' ? $q : null,
                $page,
                $perPage
            );
            $selectedInvoices = $this->getSelectionMap();
            $this->sortRowsBySelectionAndInvoiceNumber($rows, $selectedInvoices);
            $grandTotals = $this->repository->getReportTotals(
                $dateFrom,
                $dateTo,
                $cities,
                $store !== '' ? $store : null,
                $salesmanId !== '' ? $salesmanId : null,
                $q !== '' ? $q : null
            );
        } catch (Throwable $e) {
            Log::error('Invoices report failed.', ['message' => $e->getMessage()]);

            return view('reports.invoices.index', [
                'rows' => null,
                'grandTotals' => null,
                'filters' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'per_page' => $perPage,
                    'store' => $store,
                    'salesman_id' => $salesmanId,
                    'cities' => $cities,
                    'q' => $q,
                ],
                'storeOptions' => $storeOptions,
                'cityOptions' => $cityOptions,
                'cityOptionsForPicker' => ReportCityPickerOptions::fromCityNames($cityOptions),
                'salesmen' => $salesmen,
                'selectedInvoices' => $this->getSelectionMap(),
                'errorMessage' => 'Unable to load invoices report. Check logs and try again.',
            ]);
        }

        return view('reports.invoices.index', [
            'rows' => $rows,
            'grandTotals' => $grandTotals ?? null,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'per_page' => $perPage,
                'store' => $store,
                'salesman_id' => $salesmanId,
                'cities' => $cities,
                'q' => $q,
            ],
            'storeOptions' => $storeOptions,
            'cityOptions' => $cityOptions,
            'cityOptionsForPicker' => ReportCityPickerOptions::fromCityNames($cityOptions),
            'salesmen' => $salesmen,
            'selectedInvoices' => $selectedInvoices,
            'errorMessage' => null,
        ]);
    }

    public function exportListCsv(InvoicesReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        [$dateFrom, $dateTo, $cities, $store, $salesmanId, $q] = $this->listExportFilters($request);

        try {
            $rows = $this->repository->getReportRowsForExport(
                $dateFrom,
                $dateTo,
                $cities,
                $store !== '' ? $store : null,
                $salesmanId !== '' ? $salesmanId : null,
                $q !== '' ? $q : null
            );
        } catch (Throwable $e) {
            Log::error('invoices.list_csv_export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.invoices.index', $request->query()))
                ->with('error', 'Could not export invoices CSV.');
        }

        $filename = 'invoices-'.$dateFrom.'-'.$dateTo.'.csv';

        return Excel::download(
            new InvoicesReportExport($rows),
            $filename,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function exportListPdf(InvoicesReportRequest $request): Response|RedirectResponse
    {
        [$dateFrom, $dateTo, $cities, $store, $salesmanId, $q] = $this->listExportFilters($request);

        try {
            $rows = $this->repository->getReportRowsForExport(
                $dateFrom,
                $dateTo,
                $cities,
                $store !== '' ? $store : null,
                $salesmanId !== '' ? $salesmanId : null,
                $q !== '' ? $q : null
            );
            $grandTotals = $this->repository->getReportTotals(
                $dateFrom,
                $dateTo,
                $cities,
                $store !== '' ? $store : null,
                $salesmanId !== '' ? $salesmanId : null,
                $q !== '' ? $q : null
            );
        } catch (Throwable $e) {
            Log::error('invoices.list_pdf_export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.invoices.index', $request->query()))
                ->with('error', 'Could not export invoices PDF.');
        }

        $this->ensurePdfFontDirectories();
        $branding = InvoiceBrandingSettingsController::getSettings();

        $pdf = Pdf::loadView('reports.invoices.list-pdf', [
            'rows' => $rows,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'grandTotals' => $grandTotals,
            'store' => $store,
            'salesmanId' => $salesmanId,
            'searchText' => $q,
            'cities' => $cities,
            ...\App\Support\ReportPdfBranding::viewData($branding),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('invoices-'.$dateFrom.'-'.$dateTo.'.pdf');
    }

    /**
     * @return array{0: string, 1: string, 2: list<string>, 3: string, 4: string, 5: string}
     */
    private function listExportFilters(InvoicesReportRequest $request): array
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $store = trim((string) ($input['store'] ?? ''));
        $salesmanId = trim((string) ($input['salesman_id'] ?? ''));
        $q = trim((string) ($input['q'] ?? ''));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        return [$dateFrom, $dateTo, $cities, $store, $salesmanId, $q];
    }

    public function updateSelection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'string', 'max:100'],
            'selected' => ['required', 'boolean'],
        ]);

        $invoiceId = trim((string) $validated['invoice_id']);
        if ($invoiceId === '') {
            return response()->json(['ok' => false, 'message' => 'invoice_id is required.'], 422);
        }

        $map = $this->getSelectionMap();
        $map[$invoiceId] = (bool) $validated['selected'];
        $this->storeSelectionMap($map);

        return response()->json(['ok' => true]);
    }

    public function items(InvoicesReportRequest $request): JsonResponse
    {
        $invoiceId = trim((string) $request->input('invoice_id', ''));
        if ($invoiceId === '') {
            return response()->json(['ok' => false, 'message' => 'invoice_id is required.', 'rows' => []], 422);
        }

        try {
            $rows = $this->repository->getInvoiceItems($invoiceId);
            $rows = $this->assemblyPriority->sortRows($rows, 'category_name', 'item_name');
        } catch (Throwable $e) {
            Log::error('Invoice items endpoint failed.', ['message' => $e->getMessage()]);

            return response()->json(['ok' => false, 'message' => 'Could not load invoice items.', 'rows' => []], 500);
        }

        return response()->json([
            'ok' => true,
            'rows' => array_map(static fn (object $row): array => [
                'item_name' => (string) ($row->item_name ?? ''),
                'quantity' => (float) ($row->quantity ?? 0),
                'amount' => (float) ($row->amount ?? 0),
            ], $rows),
        ]);
    }

    public function printInvoice(InvoicesReportRequest $request): View|RedirectResponse
    {
        $invoiceId = trim((string) $request->input('invoice_id', ''));
        if ($invoiceId === '') {
            return redirect()->to(route('reports.invoices.index', $request->query()))->with('error', 'invoice_id is required.');
        }

        try {
            $invoice = $this->repository->getInvoiceHeader($invoiceId);
            $items = $this->repository->getInvoiceItems($invoiceId);
            $items = $this->assemblyPriority->sortRows($items, 'category_name', 'item_name');
            if ($invoice !== null) {
                $shouldResetDelivery = $this->shouldMarkNotDeliveredOnPrint($invoice);
                $updatedDeliveryRows = $this->repository->touchLastPrintDate($invoiceId, $shouldResetDelivery);
                $this->markInvoiceSelected($invoiceId);
                if ($shouldResetDelivery && $updatedDeliveryRows < 1) {
                    Log::warning('Invoice print did not update delivery rows.', [
                        'invoice_id' => $invoiceId,
                        'picked' => (int) $request->boolean('picked'),
                        'last_print_date' => (string) ($invoice->last_print_date ?? ''),
                    ]);
                }
                $invoice = $this->repository->getInvoiceHeader($invoiceId);
            }
        } catch (Throwable $e) {
            Log::error('Invoice print view failed.', ['message' => $e->getMessage()]);

            return redirect()->to(route('reports.invoices.index', $request->query()))->with('error', 'Could not open invoice.');
        }

        if ($invoice === null) {
            return redirect()->to(route('reports.invoices.index', $request->query()))->with('error', 'Invoice not found.');
        }

        $branding = InvoiceBrandingSettingsController::getSettings();

        return view('reports.invoices.print', [
            'invoice' => $invoice,
            'items' => $items,
            'branding' => $branding,
            'brandingLogoDataUri' => \App\Support\ReportPdfBranding::logoDataUri($branding),
        ]);
    }

    public function exportInvoicePdf(InvoicesReportRequest $request): Response|RedirectResponse
    {
        $invoiceId = trim((string) $request->input('invoice_id', ''));
        if ($invoiceId === '') {
            return redirect()->to(route('reports.invoices.index', $request->query()))->with('error', 'invoice_id is required.');
        }

        try {
            $invoice = $this->repository->getInvoiceHeader($invoiceId);
            $items = $this->repository->getInvoiceItems($invoiceId);
            $items = $this->assemblyPriority->sortRows($items, 'category_name', 'item_name');
            if ($invoice !== null) {
                $shouldResetDelivery = $this->shouldMarkNotDeliveredOnPrint($invoice);
                $updatedDeliveryRows = $this->repository->touchLastPrintDate($invoiceId, $shouldResetDelivery);
                $this->markInvoiceSelected($invoiceId);
                if ($shouldResetDelivery && $updatedDeliveryRows < 1) {
                    Log::warning('Invoice PDF export did not update delivery rows.', [
                        'invoice_id' => $invoiceId,
                        'picked' => (int) $request->boolean('picked'),
                        'last_print_date' => (string) ($invoice->last_print_date ?? ''),
                    ]);
                }
                $invoice = $this->repository->getInvoiceHeader($invoiceId);
            }
        } catch (Throwable $e) {
            Log::error('Invoice PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()->to(route('reports.invoices.index', $request->query()))->with('error', 'Could not export invoice PDF.');
        }

        if ($invoice === null) {
            return redirect()->to(route('reports.invoices.index', $request->query()))->with('error', 'Invoice not found.');
        }

        $this->ensurePdfFontDirectories();
        $branding = InvoiceBrandingSettingsController::getSettings();

        $pdf = Pdf::loadView('reports.invoices.pdf', [
            'invoice' => $invoice,
            'items' => $items,
            'branding' => $branding,
            'brandingLogoDataUri' => \App\Support\ReportPdfBranding::logoDataUri($branding),
        ])->setPaper('a4', 'portrait');
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
        $canvas->page_text(8, $canvas->get_height() - 18, 'Page {PAGE_NUM}/{PAGE_COUNT}', $font, 10, [0, 0, 0]);

        $invoiceNo = (string) ($invoice->invoice_no ?? $invoiceId);
        $output = $dompdf->output();

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'invoice-'.$invoiceNo.'.pdf'
            ),
        ]);
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
            Log::warning('invoice_logo_data_uri_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function shouldMarkNotDeliveredOnPrint(object $invoice): bool
    {
        return ! request()->boolean('picked')
            && empty($invoice->last_print_date);
    }

    private function markInvoiceSelected(string $invoiceId): void
    {
        $map = $this->getSelectionMap();
        $map[$invoiceId] = true;
        $this->storeSelectionMap($map);
    }

    /**
     * @return array<string, bool>
     */
    private function getSelectionMap(): array
    {
        try {
            /** @var mixed $raw */
            $raw = Cache::store('database')->get(self::SELECTION_CACHE_KEY, []);
        } catch (Throwable) {
            /** @var mixed $raw */
            $raw = Cache::get(self::SELECTION_CACHE_KEY, []);
        }

        if (! is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $invoiceId => $selected) {
            if (! is_string($invoiceId) || trim($invoiceId) === '') {
                continue;
            }
            $map[$invoiceId] = (bool) $selected;
        }

        return $map;
    }

    /**
     * @param  array<string, bool>  $map
     */
    private function storeSelectionMap(array $map): void
    {
        try {
            Cache::store('database')->forever(self::SELECTION_CACHE_KEY, $map);
        } catch (Throwable) {
            Cache::forever(self::SELECTION_CACHE_KEY, $map);
        }
    }

    private function ensurePdfFontDirectories(): void
    {
        $fontDir = (string) config('dompdf.options.font_dir', storage_path('fonts'));
        $fontCache = (string) config('dompdf.options.font_cache', $fontDir);
        if ($fontDir !== '' && ! File::isDirectory($fontDir)) {
            File::ensureDirectoryExists($fontDir, 0755, true);
        }
        if ($fontCache !== '' && ! File::isDirectory($fontCache)) {
            File::ensureDirectoryExists($fontCache, 0755, true);
        }
    }

    /**
     * @param  array<string, bool>  $selectedInvoices
     */
    private function sortRowsBySelectionAndInvoiceNumber(
        LengthAwarePaginator $rows,
        array $selectedInvoices
    ): void {
        $items = $rows->getCollection()->all();

        usort($items, function (object $a, object $b) use ($selectedInvoices): int {
            $aInvoiceId = trim((string) ($a->invoice_id ?? ''));
            $bInvoiceId = trim((string) ($b->invoice_id ?? ''));
            $aPicked = ! empty($selectedInvoices[$aInvoiceId]);
            $bPicked = ! empty($selectedInvoices[$bInvoiceId]);

            if ($aPicked !== $bPicked) {
                return ($aPicked ? 1 : 0) <=> ($bPicked ? 1 : 0);
            }

            $invoiceNoComparison = strnatcasecmp(
                trim((string) ($a->invoice_no ?? '')),
                trim((string) ($b->invoice_no ?? ''))
            );
            if ($invoiceNoComparison !== 0) {
                return $invoiceNoComparison;
            }

            return strnatcasecmp($aInvoiceId, $bInvoiceId);
        });

        $rows->setCollection(collect($items));
    }
}
