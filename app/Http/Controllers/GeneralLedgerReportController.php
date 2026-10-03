<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\GeneralLedgerReportExport;
use App\Http\Requests\GeneralLedgerReportRequest;
use App\Repositories\GeneralLedgerReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class GeneralLedgerReportController extends Controller
{
    public function __construct(
        private readonly GeneralLedgerReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository
    ) {}

    public function index(GeneralLedgerReportRequest $request): View
    {
        $filters = $this->normalizedFilters($request);
        $options = $this->filterOptions($filters);

        if ($filters['date_from'] === '' || $filters['date_to'] === '') {
            return view('reports.general-ledger.index', array_merge($options, [
                'rows' => null,
                'filters' => $filters,
                'totalDebit' => 0.0,
                'totalCredit' => 0.0,
                'periodDebit' => 0.0,
                'periodCredit' => 0.0,
                'errorMessage' => null,
                'needsDates' => true,
            ]));
        }

        if ($filters['account_id'] === '' && $filters['salesman_id'] === '') {
            return view('reports.general-ledger.index', array_merge($options, [
                'rows' => null,
                'filters' => $filters,
                'totalDebit' => 0.0,
                'totalCredit' => 0.0,
                'periodDebit' => 0.0,
                'periodCredit' => 0.0,
                'errorMessage' => null,
                'needsDates' => false,
            ]));
        }

        try {
            $allRows = $this->fetchRows($filters);
            $totals = $this->sumTotals($allRows);
            $rows = $this->paginateRows($allRows, $filters['page'], $filters['per_page']);
        } catch (Throwable $e) {
            Log::error('general_ledger.report_failed', ['message' => $e->getMessage()]);

            return view('reports.general-ledger.index', array_merge($options, [
                'rows' => null,
                'filters' => $filters,
                'totalDebit' => 0.0,
                'totalCredit' => 0.0,
                'periodDebit' => 0.0,
                'periodCredit' => 0.0,
                'errorMessage' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to load general ledger.',
                'needsDates' => false,
            ]));
        }

        return view('reports.general-ledger.index', array_merge($options, [
            'rows' => $rows,
            'filters' => $filters,
            'totalDebit' => $totals['total_debit'],
            'totalCredit' => $totals['total_credit'],
            'periodDebit' => $totals['period_debit'],
            'periodCredit' => $totals['period_credit'],
            'errorMessage' => null,
            'needsDates' => false,
        ]));
    }

    public function apiAccounts(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        try {
            $rows = $this->repository->searchAccounts($q, 40);
        } catch (Throwable $e) {
            Log::warning('general_ledger.api_accounts_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'message' => 'Could not search accounts.',
                'rows' => [],
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'rows' => $rows,
        ]);
    }

    public function exportPdf(GeneralLedgerReportRequest $request): Response|RedirectResponse
    {
        $payload = $this->exportPayload($request);
        if ($payload instanceof RedirectResponse) {
            return $payload;
        }

        $pdf = Pdf::loadView('reports.general-ledger.pdf', array_merge($payload, ReportPdfBranding::viewData()))
            ->setPaper('a4', 'landscape');

        return $pdf->download('general-ledger.pdf');
    }

    public function exportCsv(GeneralLedgerReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $payload = $this->exportPayload($request);
        if ($payload instanceof RedirectResponse) {
            return $payload;
        }

        return Excel::download(
            new GeneralLedgerReportExport(
                $payload['rows'],
                $payload['periodDebit'],
                $payload['periodCredit']
            ),
            'general-ledger.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @return array{
     *     rows: list<stdClass>,
     *     filters: array<string, mixed>,
     *     accountLabel: string,
     *     salesmanName: string,
     *     yearName: string,
     *     currencyLabel: string,
     *     totalDebit: float,
     *     totalCredit: float,
     *     periodDebit: float,
     *     periodCredit: float
     * }|RedirectResponse
     */
    private function exportPayload(GeneralLedgerReportRequest $request): array|RedirectResponse
    {
        $filters = $this->normalizedFilters($request);
        if ($filters['date_from'] === '' || $filters['date_to'] === '') {
            return redirect()
                ->route('reports.general-ledger.index', $request->query())
                ->with('error', 'Choose a date range before exporting.');
        }
        if ($filters['account_id'] === '' && $filters['salesman_id'] === '') {
            return redirect()
                ->route('reports.general-ledger.index', $request->query())
                ->with('error', 'Choose an account and/or salesman before exporting.');
        }

        try {
            $rows = $this->fetchRows($filters);
        } catch (Throwable $e) {
            Log::error('general_ledger.export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.general-ledger.index', $request->query())
                ->with('error', 'Unable to export general ledger.');
        }

        if (count($rows) > GeneralLedgerReportRepository::MAX_EXPORT_ROWS) {
            $rows = array_slice($rows, 0, GeneralLedgerReportRepository::MAX_EXPORT_ROWS);
        }

        $totals = $this->sumTotals($rows);
        $options = $this->filterOptions($filters);

        return [
            'rows' => $rows,
            'filters' => $filters,
            'accountLabel' => $options['accountLabel'],
            'salesmanName' => $options['salesmanName'],
            'yearName' => $options['yearName'],
            'currencyLabel' => $options['currencyLabel'],
            'totalDebit' => $totals['total_debit'],
            'totalCredit' => $totals['total_credit'],
            'periodDebit' => $totals['period_debit'],
            'periodCredit' => $totals['period_credit'],
        ];
    }

    /**
     * @return array{
     *     date_from: string,
     *     date_to: string,
     *     year_id: string,
     *     account_id: string,
     *     salesman_id: string,
     *     currency: int,
     *     city: string,
     *     account_type: int,
     *     show_as_summary: bool,
     *     transaction_type_id: string,
     *     agent_id: string,
     *     cross_account_id: string,
     *     per_page: int,
     *     page: int
     * }
     */
    private function normalizedFilters(GeneralLedgerReportRequest $request): array
    {
        $validated = $request->validated();
        $yearId = $this->repository->normalizeGuid((string) ($validated['year_id'] ?? '')) ?? '';
        if (! $request->query->has('year_id') && $yearId === '') {
            $yearId = (string) ($this->repository->resolveCurrentYearId() ?? '');
        }

        $dateFrom = (string) ($validated['date_from'] ?? '');
        $dateTo = (string) ($validated['date_to'] ?? '');
        if (! $request->query->has('date_from') && ! $request->query->has('date_to') && $dateFrom === '' && $dateTo === '') {
            $dateFrom = now()->startOfMonth()->format('Y-m-d');
            $dateTo = now()->format('Y-m-d');
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'year_id' => $yearId,
            'account_id' => $this->repository->normalizeGuid((string) ($validated['account_id'] ?? '')) ?? '',
            'salesman_id' => $this->repository->normalizeGuid((string) ($validated['salesman_id'] ?? '')) ?? '',
            'currency' => (int) ($validated['currency'] ?? 0),
            'city' => trim((string) ($validated['city'] ?? '')),
            'account_type' => (int) ($validated['account_type'] ?? 0),
            'show_as_summary' => (bool) ($validated['show_as_summary'] ?? false),
            'transaction_type_id' => $this->repository->normalizeGuid((string) ($validated['transaction_type_id'] ?? '')) ?? '',
            'agent_id' => $this->repository->normalizeGuid((string) ($validated['agent_id'] ?? '')) ?? '',
            'cross_account_id' => $this->repository->normalizeGuid((string) ($validated['cross_account_id'] ?? '')) ?? '',
            'per_page' => (int) ($validated['per_page'] ?? 100),
            'page' => max(1, (int) ($validated['page'] ?? 1)),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<stdClass>
     */
    private function fetchRows(array $filters): array
    {
        return $this->repository->getLedgerRows(
            (string) $filters['date_from'],
            (string) $filters['date_to'],
            (string) $filters['year_id'] !== '' ? (string) $filters['year_id'] : null,
            (string) $filters['account_id'] !== '' ? (string) $filters['account_id'] : null,
            (string) $filters['salesman_id'] !== '' ? (string) $filters['salesman_id'] : null,
            (int) $filters['currency'],
            (string) $filters['city'],
            (int) $filters['account_type'],
            (bool) $filters['show_as_summary'],
            (string) $filters['transaction_type_id'] !== '' ? (string) $filters['transaction_type_id'] : null,
            (string) $filters['agent_id'] !== '' ? (string) $filters['agent_id'] : null,
            (string) $filters['cross_account_id'] !== '' ? (string) $filters['cross_account_id'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     salesmen: list<array{id: string, name: string}>,
     *     yearOptions: list<stdClass>,
     *     currencyOptions: list<stdClass>,
     *     cityOptions: list<string>,
     *     transactionTypes: list<stdClass>,
     *     agents: list<stdClass>,
     *     accountLabel: string,
     *     crossAccountLabel: string,
     *     salesmanName: string,
     *     yearName: string,
     *     currencyLabel: string
     * }
     */
    private function filterOptions(array $filters): array
    {
        $salesmen = $this->visitsRepository->getSalesmanOptions();
        $yearOptions = $this->repository->getYearOptions();
        $currencyOptions = $this->repository->getCurrencyOptions();

        return [
            'salesmen' => $salesmen,
            'yearOptions' => $yearOptions,
            'currencyOptions' => $currencyOptions,
            'cityOptions' => $this->visitsRepository->getCityOptions(),
            'transactionTypes' => $this->repository->getTransactionTypeOptions(),
            'agents' => $this->repository->getAgentOptions(),
            'accountLabel' => $this->repository->resolveAccountLabel((string) ($filters['account_id'] ?? '')),
            'crossAccountLabel' => $this->repository->resolveAccountLabel((string) ($filters['cross_account_id'] ?? '')),
            'salesmanName' => $this->resolveSalesmanName($salesmen, (string) ($filters['salesman_id'] ?? '')),
            'yearName' => $this->resolveYearName($yearOptions, (string) ($filters['year_id'] ?? '')),
            'currencyLabel' => $this->resolveCurrencyLabel($currencyOptions, (int) ($filters['currency'] ?? 0)),
        ];
    }

    /**
     * @param  list<array{id: string, name: string}>  $salesmen
     */
    private function resolveSalesmanName(array $salesmen, string $salesmanId): string
    {
        if ($salesmanId === '') {
            return '';
        }
        foreach ($salesmen as $salesman) {
            if (($salesman['id'] ?? '') === $salesmanId) {
                return (string) ($salesman['name'] ?? '');
            }
        }

        return $salesmanId;
    }

    /**
     * @param  list<stdClass>  $years
     */
    private function resolveYearName(array $years, string $yearId): string
    {
        if ($yearId === '') {
            return 'All years';
        }
        foreach ($years as $year) {
            if ((string) ($year->year_id ?? '') === $yearId) {
                return (string) ($year->year_name ?? $yearId);
            }
        }

        return $yearId;
    }

    /**
     * @param  list<stdClass>  $currencies
     */
    private function resolveCurrencyLabel(array $currencies, int $currency): string
    {
        if ($currency === 0) {
            return 'Base (all currencies converted)';
        }
        foreach ($currencies as $row) {
            if ((int) ($row->currency_id ?? 0) === $currency) {
                return (string) ($row->currency_name ?? ('Currency '.$currency));
            }
        }

        return 'Currency '.$currency;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return array{total_debit: float, total_credit: float, period_debit: float, period_credit: float}
     */
    private function sumTotals(array $rows): array
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $periodDebit = 0.0;
        $periodCredit = 0.0;
        foreach ($rows as $row) {
            $debit = (float) ($row->debit ?? 0);
            $credit = (float) ($row->credit ?? 0);
            $totalDebit += $debit;
            $totalCredit += $credit;
            if ((string) ($row->flag ?? '1') !== '0') {
                $periodDebit += $debit;
                $periodCredit += $credit;
            }
        }

        return [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'period_debit' => $periodDebit,
            'period_credit' => $periodCredit,
        ];
    }

    /**
     * @param  list<stdClass>  $rows
     * @return LengthAwarePaginator<int, stdClass>
     */
    private function paginateRows(array $rows, int $page, int $perPage): LengthAwarePaginator
    {
        $perPage = max(1, min(250, $perPage));
        $page = max(1, $page);
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new Paginator($slice, $total, $perPage, $page, [
            'path' => route('reports.general-ledger.index'),
            'query' => request()->query(),
        ]);
    }
}
