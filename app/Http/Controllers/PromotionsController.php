<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\PromotionsScheduleExport;
use App\Http\Requests\PromotionsAssignmentStoreRequest;
use App\Http\Requests\PromotionsAssignmentUpdateRequest;
use App\Http\Requests\PromotionsPromoterStoreRequest;
use App\Http\Requests\PromotionsScheduleExportRequest;
use App\Repositories\DamagesCatalogRepository;
use App\Services\PromotionsScheduleService;
use App\Services\PromotionsSqliteService;
use App\Support\PromotionsWeekdays;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PromotionsController extends Controller
{
    public function __construct(
        private readonly PromotionsSqliteService $promotions,
        private readonly PromotionsScheduleService $schedule,
        private readonly DamagesCatalogRepository $clientCatalog
    ) {}

    public function index(Request $request): View
    {
        $this->promotions->ensureReady();

        $tab = (string) $request->query('tab', 'setup');
        if (! in_array($tab, ['setup', 'assignments', 'schedule'], true)) {
            $tab = 'setup';
        }

        $promoters = $this->promotions->listPromoters();
        $promoterId = (int) $request->query('promoter_id', 0);
        if ($promoterId < 1 && $promoters !== []) {
            $promoterId = (int) ($promoters[0]->id ?? 0);
        }

        $selectedPromoter = $promoterId > 0 ? $this->promotions->getPromoter($promoterId) : null;
        $assignments = $promoterId > 0 ? $this->promotions->listAssignmentsForPromoter($promoterId) : [];

        $weekStart = (string) $request->query('week_start', PromotionsWeekdays::normalizeWeekStart(Carbon::now()->toDateString()));
        $scheduleSheet = null;
        if ($tab === 'schedule' && $promoterId > 0 && $assignments !== []) {
            try {
                $scheduleSheet = $this->schedule->buildSheetForPromoter($promoterId, $weekStart);
            } catch (Throwable $e) {
                Log::warning('promotions.schedule_build_failed', ['message' => $e->getMessage()]);
            }
        }

        $editPromoterId = (int) $request->query('edit_promoter', 0);
        $editingPromoter = $editPromoterId > 0 ? $this->promotions->getPromoter($editPromoterId) : null;

        return view('reports.promotions.index', [
            'tab' => $tab,
            'promoters' => $promoters,
            'promoterId' => $promoterId,
            'selectedPromoter' => $selectedPromoter,
            'assignments' => $assignments,
            'weekdays' => PromotionsWeekdays::allowedWeekdayNumbers(),
            'weekStart' => $weekStart,
            'scheduleSheet' => $scheduleSheet,
            'editingPromoter' => $editingPromoter,
            'editPromoterId' => $editPromoterId > 0 ? $editPromoterId : null,
        ]);
    }

    public function apiClients(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        try {
            $rows = $this->clientCatalog->searchClients($q, 35);
        } catch (Throwable $e) {
            Log::warning('promotions.api_clients_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'message' => 'Could not search clients. Check SQL Server connection and .env credentials.',
                'rows' => [],
            ], 500);
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

    public function storePromoter(PromotionsPromoterStoreRequest $request): RedirectResponse
    {
        try {
            $this->promotions->createPromoter(
                (string) $request->validated('employee_name'),
                (string) ($request->validated('vehicle') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithTab($request, 'setup')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('promotions.store_promoter_failed', ['message' => $e->getMessage()]);

            return $this->redirectWithTab($request, 'setup')->with('error', 'Could not save promoter.');
        }

        return $this->redirectWithTab($request, 'setup')->with('status', 'Promoter added.');
    }

    public function updatePromoter(PromotionsPromoterStoreRequest $request, int $promoter): RedirectResponse
    {
        try {
            $this->promotions->updatePromoter(
                $promoter,
                (string) $request->validated('employee_name'),
                (string) ($request->validated('vehicle') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithTab($request, 'setup', ['edit_promoter' => $promoter])->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('promotions.update_promoter_failed', ['message' => $e->getMessage()]);

            return $this->redirectWithTab($request, 'setup', ['edit_promoter' => $promoter])->with('error', 'Could not update promoter.');
        }

        return $this->redirectWithTab($request, 'setup')->with('status', 'Promoter updated.');
    }

    public function destroyPromoter(Request $request, int $promoter): RedirectResponse
    {
        try {
            $this->promotions->deletePromoter($promoter);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithTab($request, 'setup')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('promotions.delete_promoter_failed', ['message' => $e->getMessage()]);

            return $this->redirectWithTab($request, 'setup')->with('error', 'Could not delete promoter.');
        }

        return $this->redirectWithTab($request, 'setup')->with('status', 'Promoter deleted.');
    }

    public function storeAssignment(PromotionsAssignmentStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $promoterId = (int) $validated['promoter_id'];

        try {
            $this->promotions->assignClient(
                $promoterId,
                (string) $validated['client_account_id'],
                (string) $validated['client_name'],
                $request->resolvedVisitDays()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithTab($request, 'assignments', ['promoter_id' => $promoterId])->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('promotions.assign_client_failed', ['message' => $e->getMessage()]);

            return $this->redirectWithTab($request, 'assignments', ['promoter_id' => $promoterId])->with('error', 'Could not assign client.');
        }

        return $this->redirectWithTab($request, 'assignments', ['promoter_id' => $promoterId])->with('status', 'Client assigned.');
    }

    public function updateAssignment(PromotionsAssignmentUpdateRequest $request, int $assignment): RedirectResponse
    {
        $validated = $request->validated();
        $promoterId = (int) $validated['promoter_id'];

        try {
            $this->promotions->updateAssignment($assignment, $request->resolvedVisitDays());
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithTab($request, 'assignments', ['promoter_id' => $promoterId])->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('promotions.update_assignment_failed', ['message' => $e->getMessage()]);

            return $this->redirectWithTab($request, 'assignments', ['promoter_id' => $promoterId])->with('error', 'Could not update assignment.');
        }

        return $this->redirectWithTab($request, 'assignments', ['promoter_id' => $promoterId])->with('status', 'Visit days updated.');
    }

    public function destroyAssignment(Request $request, int $assignment): RedirectResponse
    {
        $promoterId = (int) $request->query('promoter_id', 0);

        try {
            $this->promotions->deleteAssignment($assignment);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithTab($request, 'assignments', ['promoter_id' => $promoterId])->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('promotions.delete_assignment_failed', ['message' => $e->getMessage()]);

            return $this->redirectWithTab($request, 'assignments', ['promoter_id' => $promoterId])->with('error', 'Could not remove assignment.');
        }

        return $this->redirectWithTab($request, 'assignments', ['promoter_id' => $promoterId])->with('status', 'Client removed from promoter.');
    }

    public function exportPdf(PromotionsScheduleExportRequest $request): Response|RedirectResponse
    {
        $validated = $request->validated();
        $promoterId = (int) $validated['promoter_id'];
        $weekStart = (string) $validated['week_start'];

        try {
            $sheet = $this->schedule->buildSheetForPromoter($promoterId, $weekStart);
            if (($sheet['max_rows'] ?? 0) === 0 && $this->promotions->listAssignmentsForPromoter($promoterId) === []) {
                throw new \InvalidArgumentException('This promoter has no assigned clients.');
            }
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('reports.promotions.index', array_merge($request->query(), [
                'tab' => 'schedule',
                'promoter_id' => $promoterId,
                'week_start' => $weekStart,
            ]))->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('promotions.export_pdf_failed', ['message' => $e->getMessage()]);

            return redirect()->route('reports.promotions.index', $request->query())->with('error', 'Could not export PDF.');
        }

        $promoterName = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($sheet['promoter']->employee_name ?? 'promoter')) ?? 'promoter';

        $pdf = Pdf::loadView('reports.promotions.schedule-pdf', [
            'sheets' => [$sheet],
            'weekStart' => $weekStart,
            ...ReportPdfBranding::viewData(),
        ]);

        return $pdf->download('promotions-'.$promoterName.'-'.$weekStart.'.pdf');
    }

    public function exportCsv(PromotionsScheduleExportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $validated = $request->validated();
        $promoterId = (int) $validated['promoter_id'];
        $weekStart = (string) $validated['week_start'];

        try {
            $sheet = $this->schedule->buildSheetForPromoter($promoterId, $weekStart);
            if ($this->promotions->listAssignmentsForPromoter($promoterId) === []) {
                throw new \InvalidArgumentException('This promoter has no assigned clients.');
            }
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('reports.promotions.index', array_merge($request->query(), [
                'tab' => 'schedule',
                'promoter_id' => $promoterId,
                'week_start' => $weekStart,
            ]))->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('promotions.export_csv_failed', ['message' => $e->getMessage()]);

            return redirect()->route('reports.promotions.index', $request->query())->with('error', 'Could not export CSV.');
        }

        $promoterName = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($sheet['promoter']->employee_name ?? 'promoter')) ?? 'promoter';

        return Excel::download(
            new PromotionsScheduleExport($sheet),
            'promotions-'.$promoterName.'-'.$weekStart.'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function redirectWithTab(Request $request, string $tab, array $extra = []): RedirectResponse
    {
        $query = array_merge($request->query(), $extra, ['tab' => $tab]);

        return redirect()->route('reports.promotions.index', $query);
    }
}
