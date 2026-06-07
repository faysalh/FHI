<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\DamagesReportExport;
use App\Http\Requests\DamagesEntryStoreRequest;
use App\Http\Requests\DamagesIndexRequest;
use App\Http\Requests\DamagesPackagingStoreRequest;
use App\Http\Requests\DamagesReportPdfRequest;
use App\Repositories\DamagesCatalogRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Repositories\StorageItemsReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\DamagesSqliteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DamagesReportController extends Controller
{
    public function __construct(
        private readonly DamagesSqliteService $damagesSqlite,
        private readonly StorageItemsReportRepository $itemsRepository,
        private readonly DamagesCatalogRepository $catalogRepository,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly SalesBySalesmanReportRepository $salesBySalesmanRepository
    ) {}

    public function index(DamagesIndexRequest $request): View
    {
        $f = $request->filters();
        $page = max(1, (int) $request->input('page', 1));
        $perPage = $f['per_page'];

        $listFilters = [
            'date_from' => $f['date_from'],
            'date_to' => $f['date_to'],
            'client_q' => $f['client_q'],
            'item_q' => $f['item_q'],
            'salesman_id' => $f['salesman_id'],
        ];

        $entries = $this->damagesSqlite->paginateEntries($listFilters, $page, $perPage);
        $packaging = $this->damagesSqlite->listPackaging();
        $totalsAll = $this->damagesSqlite->aggregateEntryTotals($listFilters);
        $sumAmountAll = (float) ($totalsAll->sum_amount ?? 0);
        $sumPiecesAll = (int) ($totalsAll->sum_pieces ?? 0);
        $sumAmountPage = 0.0;
        $sumPiecesPage = 0;
        foreach ($entries->items() as $row) {
            $sumAmountPage += (float) ($row->amount_total ?? 0);
            $sumPiecesPage += (int) ($row->damaged_pieces ?? 0);
        }

        return view('reports.damages.index', [
            'tab' => $f['tab'],
            'entries' => $entries,
            'packaging' => $packaging,
            'filters' => $f,
            'catalogAvailable' => $this->catalogAvailable(),
            'salesmen' => $this->visitsRepository->getSalesmanOptions(),
            'sumAmountAll' => $sumAmountAll,
            'sumPiecesAll' => $sumPiecesAll,
            'sumAmountPage' => $sumAmountPage,
            'sumPiecesPage' => $sumPiecesPage,
        ]);
    }

    public function storePackaging(DamagesPackagingStoreRequest $request): RedirectResponse
    {
        $v = $request->validated();
        $this->damagesSqlite->upsertPackaging(
            (string) $v['main_item_id'],
            (string) $v['item_name'],
            (int) $v['pieces_per_main_unit']
        );

        return redirect()
            ->to(route('reports.damages.index', array_merge($request->only(['date_from', 'date_to', 'client_q', 'item_q', 'salesman_id', 'per_page']), ['tab' => 'packaging'])))
            ->with('status', 'Packaging saved locally for this item.');
    }

    public function deletePackaging(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');
        if ($id < 1) {
            return back()->with('error', 'Invalid packaging id.');
        }

        $this->damagesSqlite->deletePackaging($id);

        return redirect()
            ->to(route('reports.damages.index', array_merge($request->only(['date_from', 'date_to', 'client_q', 'item_q', 'salesman_id', 'per_page']), ['tab' => 'packaging'])))
            ->with('status', 'Packaging rule removed.');
    }

    public function storeEntry(DamagesEntryStoreRequest $request): RedirectResponse
    {
        $v = $request->validated();
        $mainItemId = (string) $v['main_item_id'];
        $occurredDate = (string) $v['occurred_date'];

        $pack = $this->damagesSqlite->getPackagingForMainItem($mainItemId);
        $piecesPer = $pack !== null ? max(1, (int) $pack->pieces_per_main_unit) : 1;
        $noPackaging = $pack === null;

        $client = $this->catalogRepository->findClientById((string) $v['client_account_id']);
        $item = $this->itemsRepository->getStoreItemDisplay($mainItemId);
        if ($client === null || $item === null) {
            return back()
                ->with('error', 'Could not verify the client or item on the main database.')
                ->withInput();
        }

        $tier = $this->salesBySalesmanRepository->getClientPriceTierZeroToFourForAccount((string) $v['client_account_id']);
        $priced = $this->itemsRepository->resolveDamagesCartonPriceForDamagesEntry(
            (string) $v['client_account_id'],
            $mainItemId,
            $occurredDate,
            $tier
        );
        $cartonPrice = round((float) ($priced['price'] ?? 0.0), 6);
        $priceSource = (string) ($priced['source'] ?? 'tier_catalog');

        $damagedPieces = (int) $v['damaged_pieces'];
        $piecePrice = $piecesPer > 0 ? $cartonPrice / $piecesPer : 0.0;
        $amount = round($piecePrice * $damagedPieces, 2);

        $salesmanId = trim((string) ($v['salesman_id'] ?? ''));
        $salesmanName = '';
        if ($salesmanId !== '') {
            foreach ($this->visitsRepository->getSalesmanOptions() as $opt) {
                if (trim((string) ($opt['id'] ?? '')) === $salesmanId) {
                    $salesmanName = trim((string) ($opt['name'] ?? ''));
                    break;
                }
            }
        } else {
            $sm = $this->catalogRepository->getSalesmanForClientAccount((string) $v['client_account_id']);
            if ($sm !== null) {
                $salesmanId = trim((string) ($sm->salesman_id ?? ''));
                $salesmanName = trim((string) ($sm->salesman_name ?? ''));
            }
        }

        $this->damagesSqlite->insertEntry(
            $occurredDate,
            $mainItemId,
            (string) ($item->item_name ?? ''),
            (string) ($client->account_id ?? ''),
            (string) ($client->client_name ?? ''),
            $salesmanId !== '' ? $salesmanId : null,
            $salesmanName !== '' ? $salesmanName : null,
            $damagedPieces,
            $piecesPer,
            $cartonPrice,
            $amount,
            isset($v['notes']) ? (string) $v['notes'] : null
        );

        $msg = 'Damaged goods entry saved on this server (local SQLite).';
        $priceColNo = $tier + 1;
        $msg .= ' Calculation: (Carton price '.display_number($cartonPrice).' ÷ '.(string) $piecesPer.' piece(s) per carton)'
            .' × '.(string) $damagedPieces.' damaged = '.display_number($amount).'.';
        if ($priceSource === 'last_client_sale') {
            $msg .= ' Carton price is taken from this client’s latest invoice line for this item on or before the damage date (unit price after line discount).';
        } else {
            $msg .= ' Carton price comes from Storage items pricing: sale price '.$priceColNo.' from item master or latest price history, using this client’s price tier '.$tier.' (used when no prior sale of this item to this client was found before that date).';
        }
        if ($noPackaging) {
            $msg .= ' No packaging rule was set for this item — 1 piece per carton was assumed (each damaged piece = one full carton at that price).';
        }
        if ($cartonPrice <= 0.0) {
            $msg .= ' No usable price was found — amount stored as 0.';
        }

        return redirect()
            ->to(route('reports.damages.index', array_merge($request->only(['date_from', 'date_to', 'client_q', 'item_q', 'salesman_id', 'per_page']), ['tab' => 'damages'])))
            ->with('status', $msg);
    }

    public function deleteEntry(Request $request): RedirectResponse
    {
        $id = (int) $request->input('id');
        if ($id < 1) {
            return back()->with('error', 'Invalid entry id.');
        }

        $deleted = $this->damagesSqlite->deleteEntry($id);
        if ($deleted < 1) {
            return back()->with('error', 'Entry not found or already removed.');
        }

        return redirect()
            ->to(route('reports.damages.index', array_merge($request->only(['date_from', 'date_to', 'client_q', 'item_q', 'salesman_id', 'per_page', 'tab', 'page']), ['tab' => 'damages'])))
            ->with('status', 'Damage entry deleted.');
    }

    public function apiItems(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $asOf = trim((string) $request->query('as_of', now()->toDateString()));
        if (strlen($asOf) > 40) {
            return response()->json(['ok' => false, 'rows' => []], 422);
        }

        try {
            $rows = $this->itemsRepository->searchStoreItemsForDamages($asOf, $q, 35);
        } catch (Throwable $e) {
            Log::warning('damages.api_items_failed', ['message' => $e->getMessage()]);

            return response()->json(['ok' => false, 'rows' => []], 500);
        }

        return response()->json([
            'ok' => true,
            'rows' => array_map(static fn (object $row): array => [
                'item_id' => (string) ($row->item_id ?? ''),
                'item_name' => (string) ($row->item_name ?? ''),
                'item_code' => (string) ($row->item_code ?? ''),
                'price1' => (float) ($row->price1 ?? 0),
            ], $rows),
        ]);
    }

    public function apiClients(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        try {
            $rows = $this->catalogRepository->searchClients($q, 35);
        } catch (Throwable $e) {
            Log::warning('damages.api_clients_failed', ['message' => $e->getMessage()]);

            return response()->json(['ok' => false, 'rows' => []], 500);
        }

        return response()->json([
            'ok' => true,
            'rows' => array_map(static fn (object $row): array => [
                'account_id' => (string) ($row->account_id ?? ''),
                'client_code' => (string) ($row->client_code ?? ''),
                'client_name' => (string) ($row->client_name ?? ''),
            ], $rows),
        ]);
    }

    public function exportPdf(DamagesReportPdfRequest $request): Response|RedirectResponse
    {
        $f = $request->filters();

        try {
            $rows = $this->damagesSqlite->listEntriesForExport([
                'date_from' => $f['date_from'],
                'date_to' => $f['date_to'],
                'client_q' => $f['client_q'],
                'item_q' => $f['item_q'],
                'salesman_id' => $f['salesman_id'],
            ]);
        } catch (Throwable $e) {
            Log::error('damages.pdf_export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.damages.index', array_merge($f, ['tab' => 'damages'])))
                ->with('error', 'Could not build the damages report PDF.');
        }

        $this->ensurePdfFontDirectories();
        $branding = InvoiceBrandingSettingsController::getSettings();

        $sumQty = 0;
        $sumAmt = 0.0;
        foreach ($rows as $row) {
            $sumQty += (int) ($row->damaged_pieces ?? 0);
            $sumAmt += (float) ($row->amount_total ?? 0);
        }

        $pdf = Pdf::loadView('reports.damages.pdf', [
            'rows' => $rows,
            'dateFrom' => $f['date_from'],
            'dateTo' => $f['date_to'],
            'clientFilter' => $f['client_q'],
            'itemFilter' => $f['item_q'],
            'salesmanFilter' => $f['salesman_id'],
            'salesmanFilterName' => $this->resolveSalesmanNameForPdf($f['salesman_id']),
            ...\App\Support\ReportPdfBranding::viewData($branding),
            'sumQty' => $sumQty,
            'sumAmt' => $sumAmt,
        ])->setPaper('a4', 'portrait');

        $filename = 'damages-'.$f['date_from'].'-'.$f['date_to'].'.pdf';

        return $pdf->download($filename);
    }

    public function exportCsv(DamagesReportPdfRequest $request): BinaryFileResponse|RedirectResponse
    {
        $f = $request->filters();

        try {
            $rows = $this->damagesSqlite->listEntriesForExport([
                'date_from' => $f['date_from'],
                'date_to' => $f['date_to'],
                'client_q' => $f['client_q'],
                'item_q' => $f['item_q'],
                'salesman_id' => $f['salesman_id'],
            ]);
        } catch (Throwable $e) {
            Log::error('damages.csv_export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.damages.index', array_merge($f, ['tab' => 'damages'])))
                ->with('error', 'Could not build the damages CSV export.');
        }

        $filename = 'damages-'.$f['date_from'].'-'.$f['date_to'].'.csv';

        return Excel::download(
            new DamagesReportExport($rows),
            $filename,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    private function resolveSalesmanNameForPdf(string $salesmanId): string
    {
        $salesmanId = trim($salesmanId);
        if ($salesmanId === '') {
            return '';
        }

        foreach ($this->visitsRepository->getSalesmanOptions() as $opt) {
            if (trim((string) ($opt['id'] ?? '')) === $salesmanId) {
                return trim((string) ($opt['name'] ?? ''));
            }
        }

        return '';
    }

    private function catalogAvailable(): bool
    {
        try {
            return DB::getDriverName() === 'sqlsrv';
        } catch (Throwable) {
            return false;
        }
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
            Log::warning('damages.logo_data_uri_failed', ['message' => $e->getMessage()]);

            return null;
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
}
