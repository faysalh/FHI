<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SalesBySalesmanReportExport;
use App\Http\Requests\SalesBySalesmanReportRequest;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Repositories\VisitsReportRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SalesBySalesmanReportController extends Controller
{
    private const EXPORT_ROW_CAP = 10000;

    public function __construct(
        private readonly SalesBySalesmanReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository
    ) {}

    public function index(SalesBySalesmanReportRequest $request): View
    {
        $today = Carbon::now()->toDateString();
        $input = array_merge([
            'date_from' => $today,
            'date_to' => $today,
            'per_page' => 250,
            'salesman_id' => '',
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $perPage = (int) ($input['per_page'] ?? 250);
        $page = (int) ($input['page'] ?? 1);
        $salesmanIdRaw = trim((string) ($input['salesman_id'] ?? ''));
        $salesmanId = $this->repository->normalizeSalesmanId($salesmanIdRaw);

        $filters = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'per_page' => $perPage,
            'salesman_id' => $salesmanIdRaw,
        ];

        $salesmen = $this->visitsRepository->getSalesmanOptions();
        $salesmanName = '';
        if ($salesmanId !== null) {
            foreach ($salesmen as $sm) {
                if (($sm['id'] ?? '') === $salesmanId) {
                    $salesmanName = (string) ($sm['name'] ?? '');
                    break;
                }
            }
        }

        $priceGroupColumn = null;
        try {
            $priceGroupColumn = $this->repository->getResolvedClientPriceGroupColumn();
        } catch (Throwable $e) {
            Log::warning('sales_by_salesman.price_group_column_probe_failed', ['message' => $e->getMessage()]);
        }

        if ($salesmanId === null) {
            return view('reports.sales-by-salesman.index', [
                'rows' => null,
                'filters' => $filters,
                'salesmen' => $salesmen,
                'salesmanName' => $salesmanName,
                'priceGroupColumn' => $priceGroupColumn,
                'grandTotals' => null,
                'errorMessage' => null,
                'needsSalesman' => true,
            ]);
        }

        try {
            $rows = $this->repository->getReport($dateFrom, $dateTo, $salesmanId, $page, $perPage);
            $grandTotals = $this->repository->getGrandTotals($dateFrom, $dateTo, $salesmanId);
        } catch (Throwable $e) {
            Log::error('Sales by salesman report failed.', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'salesman_id' => $salesmanId,
                'message' => $e->getMessage(),
            ]);

            return view('reports.sales-by-salesman.index', [
                'rows' => null,
                'filters' => $filters,
                'salesmen' => $salesmen,
                'salesmanName' => $salesmanName,
                'priceGroupColumn' => $priceGroupColumn,
                'grandTotals' => null,
                'errorMessage' => 'Unable to load report. Check logs and try again.',
                'needsSalesman' => false,
            ]);
        }

        return view('reports.sales-by-salesman.index', [
            'rows' => $rows,
            'filters' => $filters,
            'salesmen' => $salesmen,
            'salesmanName' => $salesmanName,
            'priceGroupColumn' => $priceGroupColumn,
            'grandTotals' => $grandTotals,
            'errorMessage' => null,
            'needsSalesman' => false,
        ]);
    }

    public function exportPdf(SalesBySalesmanReportRequest $request): Response|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $salesmanId = $this->repository->normalizeSalesmanId(trim((string) ($input['salesman_id'] ?? '')));

        if ($salesmanId === null) {
            return redirect()
                ->to(route('reports.sales-by-salesman.index', $request->query()))
                ->with('error', 'Choose a salesman before exporting PDF.');
        }

        $salesmen = $this->visitsRepository->getSalesmanOptions();
        $salesmanName = '';
        foreach ($salesmen as $sm) {
            if (($sm['id'] ?? '') === $salesmanId) {
                $salesmanName = (string) ($sm['name'] ?? '');
                break;
            }
        }

        try {
            $rows = $this->repository->exportRows($dateFrom, $dateTo, $salesmanId);
            $grandTotals = $this->repository->getGrandTotals($dateFrom, $dateTo, $salesmanId);
        } catch (Throwable $e) {
            Log::error('Sales by salesman PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales-by-salesman.index', $request->query()))
                ->with('error', 'Could not export PDF. Check logs and try a narrower date range.');
        }

        if (count($rows) >= self::EXPORT_ROW_CAP) {
            return redirect()
                ->to(route('reports.sales-by-salesman.index', $request->query()))
                ->with('error', 'Export hit the row limit ('.self::EXPORT_ROW_CAP.'). Narrow the date range.');
        }

        $pdf = Pdf::loadView('reports.sales-by-salesman.pdf', [
            'rows' => $rows,
            'grandTotals' => $grandTotals,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'salesmanName' => $salesmanName,
            ...\App\Support\ReportPdfBranding::viewData(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('sales-by-salesman-'.$dateFrom.'-'.$dateTo.'.pdf');
    }

    public function exportCsv(SalesBySalesmanReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $salesmanId = $this->repository->normalizeSalesmanId(trim((string) ($input['salesman_id'] ?? '')));

        if ($salesmanId === null) {
            return redirect()
                ->to(route('reports.sales-by-salesman.index', $request->query()))
                ->with('error', 'Choose a salesman before exporting CSV.');
        }

        try {
            $rows = $this->repository->exportRows($dateFrom, $dateTo, $salesmanId);
            $grandTotals = $this->repository->getGrandTotals($dateFrom, $dateTo, $salesmanId);
        } catch (Throwable $e) {
            Log::error('Sales by salesman CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales-by-salesman.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        if (count($rows) >= self::EXPORT_ROW_CAP) {
            return redirect()
                ->to(route('reports.sales-by-salesman.index', $request->query()))
                ->with('error', 'Export hit the row limit ('.self::EXPORT_ROW_CAP.'). Narrow the date range.');
        }

        return Excel::download(
            new SalesBySalesmanReportExport($rows, $grandTotals),
            'sales-by-salesman-'.$dateFrom.'-'.$dateTo.'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }
}
