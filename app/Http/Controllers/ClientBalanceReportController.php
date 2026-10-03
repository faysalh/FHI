<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ClientBalanceReportExport;
use App\Http\Requests\ClientBalanceReportRequest;
use App\Repositories\ClientBalanceReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ClientBalanceReportController extends Controller
{
    public function __construct(
        private readonly ClientBalanceReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository
    ) {}

    public function index(ClientBalanceReportRequest $request): View
    {
        $filters = $this->normalizedFilters($request);
        $salesmen = $this->visitsRepository->getSalesmanOptions();
        $yearOptions = $this->repository->getYearOptions();
        $currencyOptions = $this->repository->getCurrencyOptions();

        if ($filters['year_id'] === '') {
            $filters['year_id'] = (string) ($this->repository->resolveCurrentYearId() ?? '');
        }

        $salesmanName = $this->resolveSalesmanName($salesmen, $filters['salesman_id']);
        $yearName = $this->resolveYearName($yearOptions, $filters['year_id']);
        $currencyLabel = $this->resolveCurrencyLabel($currencyOptions, $filters['currency']);

        if ($filters['salesman_id'] === '') {
            return view('reports.client-balance.index', [
                'rows' => null,
                'filters' => $filters,
                'salesmen' => $salesmen,
                'yearOptions' => $yearOptions,
                'currencyOptions' => $currencyOptions,
                'salesmanName' => $salesmanName,
                'yearName' => $yearName,
                'currencyLabel' => $currencyLabel,
                'grandTotal' => 0.0,
                'errorMessage' => null,
                'needsSalesman' => true,
            ]);
        }

        try {
            $allRows = $this->repository->getBalancesForSalesman(
                $filters['salesman_id'],
                $filters['year_id'] !== '' ? $filters['year_id'] : null,
                $filters['currency'],
                $filters['hide_zero']
            );
            $grandTotal = $this->sumBalances($allRows);
            $rows = $this->paginateRows($allRows, $filters['page'], $filters['per_page']);
        } catch (Throwable $e) {
            Log::error('client_balance.report_failed', ['message' => $e->getMessage()]);

            return view('reports.client-balance.index', [
                'rows' => null,
                'filters' => $filters,
                'salesmen' => $salesmen,
                'yearOptions' => $yearOptions,
                'currencyOptions' => $currencyOptions,
                'salesmanName' => $salesmanName,
                'yearName' => $yearName,
                'currencyLabel' => $currencyLabel,
                'grandTotal' => 0.0,
                'errorMessage' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to load client balances.',
                'needsSalesman' => false,
            ]);
        }

        return view('reports.client-balance.index', [
            'rows' => $rows,
            'filters' => $filters,
            'salesmen' => $salesmen,
            'yearOptions' => $yearOptions,
            'currencyOptions' => $currencyOptions,
            'salesmanName' => $salesmanName,
            'yearName' => $yearName,
            'currencyLabel' => $currencyLabel,
            'grandTotal' => $grandTotal,
            'errorMessage' => null,
            'needsSalesman' => false,
        ]);
    }

    public function exportPdf(ClientBalanceReportRequest $request): Response|RedirectResponse
    {
        $payload = $this->exportPayload($request);
        if ($payload instanceof RedirectResponse) {
            return $payload;
        }

        $pdf = Pdf::loadView('reports.client-balance.pdf', array_merge($payload, ReportPdfBranding::viewData()));

        return $pdf->download('client-balance.pdf');
    }

    public function exportCsv(ClientBalanceReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $payload = $this->exportPayload($request);
        if ($payload instanceof RedirectResponse) {
            return $payload;
        }

        return Excel::download(
            new ClientBalanceReportExport($payload['rows'], $payload['grandTotal']),
            'client-balance.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @return array{
     *     rows: list<stdClass>,
     *     filters: array{salesman_id: string, year_id: string, currency: int, hide_zero: bool, per_page: int, page: int},
     *     salesmanName: string,
     *     yearName: string,
     *     currencyLabel: string,
     *     grandTotal: float
     * }|RedirectResponse
     */
    private function exportPayload(ClientBalanceReportRequest $request): array|RedirectResponse
    {
        $filters = $this->normalizedFilters($request);
        if ($filters['salesman_id'] === '') {
            return redirect()
                ->route('reports.client-balance.index', $request->query())
                ->with('error', 'Choose a salesman before exporting.');
        }

        if ($filters['year_id'] === '') {
            $filters['year_id'] = (string) ($this->repository->resolveCurrentYearId() ?? '');
        }

        $salesmen = $this->visitsRepository->getSalesmanOptions();
        $yearOptions = $this->repository->getYearOptions();
        $currencyOptions = $this->repository->getCurrencyOptions();

        try {
            $rows = $this->repository->getBalancesForSalesman(
                $filters['salesman_id'],
                $filters['year_id'] !== '' ? $filters['year_id'] : null,
                $filters['currency'],
                $filters['hide_zero']
            );
        } catch (Throwable $e) {
            Log::error('client_balance.export_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.client-balance.index', $request->query())
                ->with('error', 'Unable to export client balances.');
        }

        if (count($rows) > ClientBalanceReportRepository::MAX_EXPORT_ROWS) {
            $rows = array_slice($rows, 0, ClientBalanceReportRepository::MAX_EXPORT_ROWS);
        }

        return [
            'rows' => $rows,
            'filters' => $filters,
            'salesmanName' => $this->resolveSalesmanName($salesmen, $filters['salesman_id']),
            'yearName' => $this->resolveYearName($yearOptions, $filters['year_id']),
            'currencyLabel' => $this->resolveCurrencyLabel($currencyOptions, $filters['currency']),
            'grandTotal' => $this->sumBalances($rows),
        ];
    }

    /**
     * @return array{salesman_id: string, year_id: string, currency: int, hide_zero: bool, per_page: int, page: int}
     */
    private function normalizedFilters(ClientBalanceReportRequest $request): array
    {
        $validated = $request->validated();
        $salesmanId = $this->repository->normalizeGuid((string) ($validated['salesman_id'] ?? '')) ?? '';
        $yearId = $this->repository->normalizeGuid((string) ($validated['year_id'] ?? '')) ?? '';

        return [
            'salesman_id' => $salesmanId,
            'year_id' => $yearId,
            'currency' => (int) ($validated['currency'] ?? 0),
            'hide_zero' => (bool) ($validated['hide_zero'] ?? false),
            'per_page' => (int) ($validated['per_page'] ?? 100),
            'page' => max(1, (int) ($validated['page'] ?? 1)),
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
            return 'Current year';
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
     */
    private function sumBalances(array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row->balance ?? 0);
        }

        return $total;
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
            'path' => route('reports.client-balance.index'),
            'query' => request()->query(),
        ]);
    }
}
