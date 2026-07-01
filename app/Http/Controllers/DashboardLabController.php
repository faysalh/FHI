<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DashboardMetricsRequest;
use App\Repositories\VisitsReportRepository;
use App\Services\DashboardGovernorateService;
use App\Services\DashboardLabMetricsService;
use App\Support\DashboardLabAsOf;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardLabController extends Controller
{
    public function __construct(
        private readonly DashboardGovernorateService $governorate,
        private readonly DashboardLabMetricsService $metrics,
        private readonly VisitsReportRepository $visits,
    ) {}

    public function index(): View
    {
        $asOf = $this->resolveAsOf(request()->query('as_of_date'));
        $governorateOptions = $this->governorate->listForSelect();
        $requestedGovernorateId = $this->requestedGovernorateId();
        $geo = $this->governorate->resolve($requestedGovernorateId);
        $initialMetrics = null;
        $dataError = $geo['error'];

        if ($geo['cities'] !== []) {
            try {
                $initialMetrics = $this->metrics->build($geo['cities'], null, $asOf);
            } catch (Throwable $e) {
                Log::error('dashboard_lab.metrics_failed', ['message' => $e->getMessage()]);
                $dataError = $dataError ?? 'Could not load lab dashboard metrics.';
            }
        }

        return view('reports.dashboard-lab.index', [
            'asOfDate' => $asOf->toDateString(),
            'todayDate' => Carbon::now()->toDateString(),
            'asOfLabel' => $asOf->format('l, j M Y'),
            'monthLabel' => $asOf->format('F Y'),
            'daySectionLabel' => DashboardLabAsOf::daySectionLabel($asOf),
            'isLiveView' => DashboardLabAsOf::isLive($asOf),
            'historicalDatesEnabled' => DashboardLabAsOf::historicalDatesEnabled(),
            'forceLive' => request()->boolean('live'),
            'governorateLabel' => $geo['label'],
            'governorateError' => $geo['error'],
            'dataError' => $dataError,
            'governorateOptions' => $governorateOptions,
            'selectedGovernorateId' => $geo['governorate_id'] ?? $requestedGovernorateId ?? 0,
            'salesmanOptions' => $this->loadSalesmanOptions(),
            'metricsUrl' => route('reports.dashboard-lab.metrics'),
            'initialMetrics' => $initialMetrics,
        ]);
    }

    public function metrics(DashboardMetricsRequest $request): JsonResponse
    {
        $geo = $this->governorate->resolve($this->governorateIdFromRequest($request));
        if ($geo['cities'] === []) {
            return response()->json([
                'ok' => false,
                'error' => $geo['error'] ?? 'Governorate is not configured.',
            ], 422);
        }

        $salesmanId = trim((string) ($request->validated('salesman_id') ?? ''));
        if ($salesmanId === '') {
            $salesmanId = null;
        }

        $asOf = $this->resolveAsOf(
            $request->validated('as_of_date'),
            (bool) ($request->validated('live') ?? false)
        );

        try {
            $payload = $this->metrics->build($geo['cities'], $salesmanId, $asOf);

            return response()->json([
                'ok' => true,
                'governorate_label' => $geo['label'],
                ...$payload,
            ]);
        } catch (Throwable $e) {
            Log::error('dashboard_lab.metrics_ajax_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'error' => 'Could not load metrics.',
            ], 500);
        }
    }

    private function resolveAsOf(mixed $dateInput, bool $forceLive = false): CarbonInterface
    {
        if (! $forceLive && request()->boolean('live')) {
            $forceLive = true;
        }

        return DashboardLabAsOf::resolve(
            is_string($dateInput) ? $dateInput : null,
            $forceLive
        );
    }

    private function requestedGovernorateId(): ?int
    {
        $id = (int) request()->query('saved_governorate_id', 0);

        return $id > 0 ? $id : null;
    }

    private function governorateIdFromRequest(DashboardMetricsRequest $request): ?int
    {
        $id = (int) ($request->validated('saved_governorate_id') ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function loadSalesmanOptions(): array
    {
        try {
            return $this->visits->getSalesmanOptions();
        } catch (Throwable $e) {
            Log::warning('dashboard_lab.salesmen_unavailable', ['message' => $e->getMessage()]);

            return [];
        }
    }
}
