<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\InvoicesReportRequest;
use App\Repositories\InvoicesReportRepository;
use App\Repositories\VisitsReportRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class InvoicesReportController extends Controller
{
    public function __construct(
        private readonly InvoicesReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository
    ) {
    }

    public function index(InvoicesReportRequest $request): View
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $perPage = (int) ($input['per_page'] ?? 25);
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
        } catch (Throwable $e) {
            Log::error('Invoices report failed.', ['message' => $e->getMessage()]);

            return view('reports.invoices.index', [
                'rows' => null,
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
                'salesmen' => $salesmen,
                'errorMessage' => 'Unable to load invoices report. Check logs and try again.',
            ]);
        }

        return view('reports.invoices.index', [
            'rows' => $rows,
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
            'salesmen' => $salesmen,
            'errorMessage' => null,
        ]);
    }

    public function items(InvoicesReportRequest $request): JsonResponse
    {
        $invoiceId = trim((string) $request->input('invoice_id', ''));
        if ($invoiceId === '') {
            return response()->json(['ok' => false, 'message' => 'invoice_id is required.', 'rows' => []], 422);
        }

        try {
            $rows = $this->repository->getInvoiceItems($invoiceId);
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
            'brandingLogoDataUri' => $this->resolveBrandingLogoDataUri($branding),
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
        } catch (Throwable $e) {
            Log::error('Invoice PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()->to(route('reports.invoices.index', $request->query()))->with('error', 'Could not export invoice PDF.');
        }

        if ($invoice === null) {
            return redirect()->to(route('reports.invoices.index', $request->query()))->with('error', 'Invoice not found.');
        }

        $branding = InvoiceBrandingSettingsController::getSettings();

        $pdf = Pdf::loadView('reports.invoices.pdf', [
            'invoice' => $invoice,
            'items' => $items,
            'branding' => $branding,
            'brandingLogoDataUri' => $this->resolveBrandingLogoDataUri($branding),
        ])->setPaper('a4', 'portrait');

        $invoiceNo = (string) ($invoice->invoice_no ?? $invoiceId);

        return $pdf->download('invoice-'.$invoiceNo.'.pdf');
    }

    /**
     * @param array<string, string> $branding
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
}

