<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\RankingsReportExport;
use App\Http\Requests\RankingsReportRequest;
use App\Repositories\RankingsReportRepository;
use App\Repositories\SalesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class RankingsReportController extends Controller
{
    public function __construct(
        private readonly RankingsReportRepository $repository,
        private readonly SalesReportRepository $salesRepository,
        private readonly VisitsReportRepository $visitsRepository,
        private readonly CitiesGovernorateSqliteService $governorates,
    ) {}

    public function index(RankingsReportRequest $request): View
    {
        return view('reports.rankings.index', $this->buildViewData($request));
    }

    public function exportPdf(RankingsReportRequest $request): Response|RedirectResponse
    {
        $data = $this->buildViewData($request);
        if (($data['errorMessage'] ?? null) !== null) {
            return redirect()
                ->route('reports.rankings.index', $request->query())
                ->with('error', 'Could not export PDF.');
        }

        $pdf = Pdf::loadView('reports.rankings.pdf', $data)->setPaper('a4', 'landscape');

        $tab = (string) ($data['filters']['tab'] ?? 'rankings');

        return $pdf->download('rankings-'.$tab.'-'.($data['filters']['date_from'] ?? '').'.pdf');
    }

    public function exportCsv(RankingsReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $data = $this->buildViewData($request);
        if (($data['errorMessage'] ?? null) !== null) {
            return redirect()
                ->route('reports.rankings.index', $request->query())
                ->with('error', 'Could not export CSV.');
        }

        $tab = (string) ($data['filters']['tab'] ?? 'rankings');

        return Excel::download(
            new RankingsReportExport(
                $data['rows'] ?? [],
                (string) ($data['filters']['tab'] ?? ''),
                in_array($data['filters']['tab'] ?? '', [RankingsReportRepository::TAB_GROWING, RankingsReportRepository::TAB_DECLINING], true),
            ),
            'rankings-'.$tab.'-'.($data['filters']['date_from'] ?? '').'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(RankingsReportRequest $request): array
    {
        $today = Carbon::now()->toDateString();
        $input = array_merge([
            'date_from' => $today,
            'date_to' => $today,
            'tab' => RankingsReportRepository::TAB_CLIENTS,
            'metric' => 'amount',
            'limit' => 10,
            'salesman_ids' => [],
            'storage' => '',
            'saved_governorate_id' => 0,
        ], $request->validated());

        $geo = $this->resolveGeoFilters($input);
        $storage = trim((string) ($input['storage'] ?? ''));
        $storageFilter = $storage !== '' ? $storage : null;

        $filters = [
            'date_from' => (string) $input['date_from'],
            'date_to' => (string) $input['date_to'],
            'tab' => $this->repository->normalizeTab((string) $input['tab']),
            'metric' => $this->repository->normalizeMetric((string) $input['metric']),
            'limit' => $this->repository->normalizeLimit((int) $input['limit']),
            'salesman_ids' => $geo['salesman_ids'],
            'storage' => $storage,
            'saved_governorate_id' => $geo['saved_governorate_id'] > 0 ? $geo['saved_governorate_id'] : '',
            'governorate_label' => $geo['governorate_label'],
        ];

        [$savedGovernorates, $salesmanOptions, $storageOptions] = $this->loadFilterOptions();

        $base = [
            'filters' => $filters,
            'savedGovernorates' => $savedGovernorates,
            'salesmanOptions' => $salesmanOptions,
            'storageOptions' => $storageOptions,
            'tabs' => $this->tabLabels(),
            'rows' => [],
            'periodTotals' => null,
            'priorPeriodLabel' => null,
            'topNSharePct' => null,
            'errorMessage' => null,
        ];

        try {
            $result = $this->repository->getRankings(
                $filters['tab'],
                $filters['date_from'],
                $filters['date_to'],
                $filters['limit'],
                $filters['metric'],
                $geo['cities'],
                $geo['salesman_ids'],
                $storageFilter
            );

            $rows = $result['rows'];
            $periodTotals = $result['period_totals'];
            $topSum = 0.0;
            foreach ($rows as $row) {
                $topSum += (float) ($row->amount ?? 0);
            }
            $periodAmount = (float) ($periodTotals->amount ?? 0);
            $topNSharePct = $periodAmount > 0 ? ($topSum / $periodAmount) * 100.0 : null;

            return array_merge($base, [
                'rows' => $rows,
                'periodTotals' => $periodTotals,
                'priorPeriodLabel' => $result['prior_period_label'],
                'topNSharePct' => $topNSharePct,
            ]);
        } catch (Throwable $e) {
            Log::error('Rankings report failed.', [
                'tab' => $filters['tab'],
                'message' => $e->getMessage(),
            ]);

            return array_merge($base, [
                'errorMessage' => 'Unable to load rankings. Check logs and try again.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{cities: list<string>, salesman_ids: list<string>, saved_governorate_id: int, governorate_label: string}
     */
    private function resolveGeoFilters(array $input): array
    {
        $savedGovernorateId = (int) ($input['saved_governorate_id'] ?? 0);
        $salesmanIds = $this->salesRepository->normalizeSalesmanIds(
            is_array($input['salesman_ids'] ?? null) ? $input['salesman_ids'] : []
        );

        $governorateCities = [];
        $governorateLabel = '';
        if ($savedGovernorateId > 0) {
            try {
                $selectedGov = $this->governorates->getGovernorateById($savedGovernorateId);
                if ($selectedGov !== null) {
                    $governorateCities = $this->salesRepository->normalizeCities((array) ($selectedGov['members'] ?? []));
                    $governorateLabel = (string) ($selectedGov['name'] ?? '');
                }
            } catch (Throwable $e) {
                Log::warning('rankings.governorate_unavailable', ['message' => $e->getMessage()]);
            }
        }

        return [
            'cities' => $governorateCities,
            'salesman_ids' => $salesmanIds,
            'saved_governorate_id' => $savedGovernorateId,
            'governorate_label' => $governorateLabel,
        ];
    }

    /**
     * @return array{0: list<object>, 1: list<array{id: string, name: string}>, 2: list<string>}
     */
    private function loadFilterOptions(): array
    {
        $savedGovernorates = [];
        $salesmanOptions = [];
        $storageOptions = [];

        try {
            $savedGovernorates = $this->governorates->listGovernorates();
        } catch (Throwable $e) {
            Log::warning('rankings.governorates_list_unavailable', ['message' => $e->getMessage()]);
        }
        try {
            $salesmanOptions = $this->visitsRepository->getSalesmanOptions();
        } catch (Throwable $e) {
            Log::warning('rankings.salesmen_unavailable', ['message' => $e->getMessage()]);
        }
        try {
            $storageOptions = $this->repository->getStorageOptions();
        } catch (Throwable $e) {
            Log::warning('rankings.storage_options_unavailable', ['message' => $e->getMessage()]);
        }

        return [$savedGovernorates, $salesmanOptions, $storageOptions];
    }

    /**
     * @return array<string, string>
     */
    private function tabLabels(): array
    {
        return [
            RankingsReportRepository::TAB_CLIENTS => 'Clients',
            RankingsReportRepository::TAB_ITEMS => 'Items',
            RankingsReportRepository::TAB_SALESMEN => 'Salesmen',
            RankingsReportRepository::TAB_CATEGORIES => 'Categories',
            RankingsReportRepository::TAB_CITIES => 'Cities',
            RankingsReportRepository::TAB_GROWING => 'Growing',
            RankingsReportRepository::TAB_DECLINING => 'Declining',
        ];
    }
}
