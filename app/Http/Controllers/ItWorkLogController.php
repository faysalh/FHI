<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ItWorkLogExport;
use App\Services\ItWorkLogSqliteService;
use App\Support\ReportAuthSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ItWorkLogController extends Controller
{
    public function __construct(
        private readonly ItWorkLogSqliteService $workLog
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return redirect()->route('login');
        }

        $filters = $this->filtersFromRequest($request);
        $this->workLog->ensureReady();
        $entries = $this->workLog->listEntries(
            $userId,
            $filters['status'],
            $filters['date_from'],
            $filters['date_to'],
            $filters['q']
        );

        $inProgress = [];
        $finished = [];
        foreach ($entries as $entry) {
            if (trim((string) ($entry->finished_at ?? '')) === '') {
                $inProgress[] = $entry;
            } else {
                $finished[] = $entry;
            }
        }

        return view('reports.it-work-log.index', [
            'inProgress' => $inProgress,
            'finished' => $finished,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->workLog->startEntry(
            $userId,
            trim((string) $data['title']),
            isset($data['notes']) ? (string) $data['notes'] : null
        );

        return redirect()
            ->route('reports.it-work-log.index')
            ->with('status', 'Work started.');
    }

    public function finish(int $entry): RedirectResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return redirect()->route('login');
        }

        if (! $this->workLog->finishEntry($entry, $userId)) {
            return redirect()
                ->route('reports.it-work-log.index')
                ->with('error', 'Could not finish that work entry.');
        }

        return redirect()
            ->route('reports.it-work-log.index')
            ->with('status', 'Work finished.');
    }

    public function update(Request $request, int $entry): RedirectResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (! $this->workLog->updateEntry(
            $entry,
            $userId,
            trim((string) $data['title']),
            isset($data['notes']) ? (string) $data['notes'] : null
        )) {
            return redirect()
                ->route('reports.it-work-log.index')
                ->with('error', 'Could not update that work entry.');
        }

        return redirect()
            ->route('reports.it-work-log.index')
            ->with('status', 'Work entry updated.');
    }

    public function destroy(int $entry): RedirectResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return redirect()->route('login');
        }

        if (! $this->workLog->deleteEntry($entry, $userId)) {
            return redirect()
                ->route('reports.it-work-log.index')
                ->with('error', 'Could not delete that work entry.');
        }

        return redirect()
            ->route('reports.it-work-log.index')
            ->with('status', 'Work entry deleted.');
    }

    public function exportPdf(Request $request): Response|RedirectResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return redirect()->route('login');
        }

        $filters = $this->filtersFromRequest($request);

        try {
            $rows = $this->workLog->listEntries(
                $userId,
                $filters['status'],
                $filters['date_from'],
                $filters['date_to'],
                $filters['q']
            );
        } catch (Throwable $e) {
            Log::error('it_work_log.pdf_export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.it-work-log.index', $filters)
                ->with('error', 'Could not build the IT work log PDF.');
        }

        $this->ensurePdfFontDirectories();
        $branding = InvoiceBrandingSettingsController::getSettings();

        $pdf = Pdf::loadView('reports.it-work-log.pdf', [
            'rows' => $rows,
            'filters' => $filters,
            ...\App\Support\ReportPdfBranding::viewData($branding),
        ])->setPaper('a4', 'portrait');

        $filename = 'it-work-log-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }

    public function exportCsv(Request $request): BinaryFileResponse|RedirectResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return redirect()->route('login');
        }

        $filters = $this->filtersFromRequest($request);

        try {
            $rows = $this->workLog->listEntries(
                $userId,
                $filters['status'],
                $filters['date_from'],
                $filters['date_to'],
                $filters['q']
            );
        } catch (Throwable $e) {
            Log::error('it_work_log.csv_export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.it-work-log.index', $filters)
                ->with('error', 'Could not build the IT work log CSV.');
        }

        $filename = 'it-work-log-'.now()->format('Y-m-d').'.csv';

        return Excel::download(new ItWorkLogExport($rows), $filename, Excel::CSV);
    }

    /**
     * @return array{status: string, date_from: string, date_to: string, q: string}
     */
    private function filtersFromRequest(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'in_progress', 'finished'], true)) {
            $status = 'all';
        }

        return [
            'status' => $status,
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'q' => trim((string) $request->query('q', '')),
        ];
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
