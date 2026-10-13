<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\MarketStudyExport;
use App\Services\MarketStudySqliteService;
use App\Support\ReportAuthSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class MarketStudyController extends Controller
{
    public function __construct(
        private readonly MarketStudySqliteService $marketStudy
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        if ($this->requireUserId() === null) {
            return redirect()->route('login');
        }

        $canView = ReportAuthSession::canViewMarketStudy();
        $canAdd = ReportAuthSession::canAddMarketStudy();
        $canEdit = ReportAuthSession::canEditMarketStudy();
        $canDelete = ReportAuthSession::canDeleteMarketStudy();

        $filters = $this->filtersFromRequest($request);
        $this->marketStudy->ensureReady();

        $entries = [];
        $insights = [
            'today_price' => null,
            'today_count' => 0,
            'range_average' => null,
            'range_count' => 0,
            'delta_pct' => null,
        ];
        $chart = ['labels' => [], 'datasets' => []];
        $marketOptions = [];
        $itemOptions = [];
        $brandOptions = [];

        if ($canView) {
            $entries = $this->marketStudy->listEntries($filters);
            $insights = $this->marketStudy->insightStats($filters);
            $chart = $this->marketStudy->chartSeries($filters);
            $marketOptions = $this->marketStudy->distinctColumn('market_name');
            $itemOptions = $this->marketStudy->distinctColumn('item_name');
            $brandOptions = $this->marketStudy->distinctColumn('brand');
        }

        return view('reports.market-study.index', [
            'entries' => $entries,
            'filters' => $filters,
            'insights' => $insights,
            'chart' => $chart,
            'marketOptions' => $marketOptions,
            'itemOptions' => $itemOptions,
            'brandOptions' => $brandOptions,
            'canView' => $canView,
            'canAdd' => $canAdd,
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'todayDate' => now()->toDateString(),
        ]);
    }

    public function suggest(Request $request): JsonResponse|RedirectResponse
    {
        if ($this->requireUserId() === null) {
            return redirect()->route('login');
        }

        if (! ReportAuthSession::canViewMarketStudy() && ! ReportAuthSession::canAddMarketStudy()) {
            abort(403, 'You do not have permission to use market study suggestions.');
        }

        $q = trim((string) $request->query('q', ''));
        $suggestions = $this->marketStudy->suggestItems($q);

        return response()->json(['suggestions' => $suggestions]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return redirect()->route('login');
        }

        if (! ReportAuthSession::canAddMarketStudy()) {
            abort(403, 'You do not have permission to add market study entries.');
        }

        $data = $this->validatedEntry($request);
        $this->marketStudy->createEntry(
            $data['market_name'],
            $data['item_name'],
            $data['brand'],
            $data['price_per_kg'],
            $data['logged_on'],
            $data['notes'],
            $userId
        );

        return redirect()
            ->route('reports.market-study.index')
            ->with('status', 'Market price logged.');
    }

    public function update(Request $request, int $entry): RedirectResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return redirect()->route('login');
        }

        if (! ReportAuthSession::canEditMarketStudy()) {
            abort(403, 'You do not have permission to edit market study entries.');
        }

        $data = $this->validatedEntry($request);
        if (! $this->marketStudy->updateEntry(
            $entry,
            $data['market_name'],
            $data['item_name'],
            $data['brand'],
            $data['price_per_kg'],
            $data['logged_on'],
            $data['notes'],
            $userId
        )) {
            return redirect()
                ->route('reports.market-study.index', $this->filtersFromRequest($request))
                ->with('error', 'Could not update that market price entry.');
        }

        return redirect()
            ->route('reports.market-study.index', $this->filtersFromRequest($request))
            ->with('status', 'Market price entry updated.');
    }

    public function destroy(Request $request, int $entry): RedirectResponse
    {
        if ($this->requireUserId() === null) {
            return redirect()->route('login');
        }

        if (! ReportAuthSession::canDeleteMarketStudy()) {
            abort(403, 'You do not have permission to delete market study entries.');
        }

        if (! $this->marketStudy->deleteEntry($entry)) {
            return redirect()
                ->route('reports.market-study.index', $this->filtersFromRequest($request))
                ->with('error', 'Could not delete that market price entry.');
        }

        return redirect()
            ->route('reports.market-study.index', $this->filtersFromRequest($request))
            ->with('status', 'Market price entry deleted.');
    }

    public function exportPdf(Request $request): Response|RedirectResponse
    {
        if ($this->requireUserId() === null) {
            return redirect()->route('login');
        }

        if (! ReportAuthSession::canViewMarketStudy()) {
            abort(403, 'You do not have permission to export market study.');
        }

        $filters = $this->filtersFromRequest($request);

        try {
            $rows = $this->marketStudy->listEntries($filters);
            $insights = $this->marketStudy->insightStats($filters);
        } catch (Throwable $e) {
            Log::error('market_study.pdf_export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.market-study.index', $filters)
                ->with('error', 'Could not build the market study PDF.');
        }

        $this->ensurePdfFontDirectories();
        $branding = InvoiceBrandingSettingsController::getSettings();

        $pdf = Pdf::loadView('reports.market-study.pdf', [
            'rows' => $rows,
            'filters' => $filters,
            'insights' => $insights,
            ...\App\Support\ReportPdfBranding::viewData($branding),
        ])->setPaper('a4', 'landscape');

        $filename = 'market-study-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }

    public function exportCsv(Request $request): BinaryFileResponse|RedirectResponse
    {
        if ($this->requireUserId() === null) {
            return redirect()->route('login');
        }

        if (! ReportAuthSession::canViewMarketStudy()) {
            abort(403, 'You do not have permission to export market study.');
        }

        $filters = $this->filtersFromRequest($request);

        try {
            $rows = $this->marketStudy->listEntries($filters);
        } catch (Throwable $e) {
            Log::error('market_study.csv_export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.market-study.index', $filters)
                ->with('error', 'Could not build the market study CSV.');
        }

        $filename = 'market-study-'.now()->format('Y-m-d').'.csv';

        return Excel::download(new MarketStudyExport($rows), $filename, Excel::CSV);
    }

    /**
     * @return array{
     *     market_name: string,
     *     item_name: string,
     *     brand: string,
     *     price_per_kg: float,
     *     logged_on: string,
     *     notes: string|null
     * }
     */
    private function validatedEntry(Request $request): array
    {
        $data = $request->validate([
            'market_name' => ['required', 'string', 'max:255'],
            'item_name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'price_per_kg' => ['required', 'numeric', 'gt:0'],
            'logged_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return [
            'market_name' => trim((string) $data['market_name']),
            'item_name' => trim((string) $data['item_name']),
            'brand' => trim((string) $data['brand']),
            'price_per_kg' => (float) $data['price_per_kg'],
            'logged_on' => trim((string) $data['logged_on']),
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
        ];
    }

    /**
     * @return array{
     *     market: string,
     *     item: string,
     *     brand: string,
     *     date_from: string,
     *     date_to: string,
     *     q: string
     * }
     */
    private function filtersFromRequest(Request $request): array
    {
        $dateFrom = trim((string) $request->input('date_from', $request->query('date_from', '')));
        $dateTo = trim((string) $request->input('date_to', $request->query('date_to', '')));

        if ($dateFrom === '' && $dateTo === '' && $request->isMethod('GET') && ! $request->has('date_from')) {
            $dateTo = now()->toDateString();
            $dateFrom = now()->copy()->subDays(29)->toDateString();
        }

        return MarketStudySqliteService::normalizeFilters(
            (string) $request->input('market', $request->query('market', '')),
            (string) $request->input('item', $request->query('item', '')),
            (string) $request->input('brand', $request->query('brand', '')),
            $dateFrom,
            $dateTo,
            (string) $request->input('q', $request->query('q', ''))
        );
    }

    private function requireUserId(): ?int
    {
        $userId = ReportAuthSession::userId();

        return ($userId !== null && $userId > 0) ? $userId : null;
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
