<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ItemBasedSalesReportExport;
use App\Http\Requests\ItemBasedSalesReportRequest;
use App\Repositories\ItemBasedSalesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ItemBasedSalesReportController extends Controller
{
    public function __construct(
        private readonly ItemBasedSalesReportRepository $repository,
        private readonly VisitsReportRepository $visits,
    ) {}

    public function index(ItemBasedSalesReportRequest $request): View
    {
        $data = $this->load($request);
        $page = max(1, (int) ($request->validated()['page'] ?? 1));
        $perPage = 100;
        $slice = array_slice($data['rows'], ($page - 1) * $perPage, $perPage);
        $data['paginator'] = new LengthAwarePaginator(
            $slice,
            count($data['rows']),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('reports.sales.index', $data);
    }

    public function exportPdf(ItemBasedSalesReportRequest $request): Response
    {
        $data = $this->load($request);

        return Pdf::loadView('reports.sales.item-pdf', array_merge($data, ReportPdfBranding::viewData()))
            ->setPaper('a4', 'landscape')
            ->download('sales-report-by-item.pdf');
    }

    public function exportCsv(ItemBasedSalesReportRequest $request): BinaryFileResponse
    {
        $data = $this->load($request);

        return Excel::download(
            new ItemBasedSalesReportExport($data['rows']),
            'sales-report-by-item.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function load(ItemBasedSalesReportRequest $request): array
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $salesmanId = trim((string) ($input['salesman_id'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $search = trim((string) ($input['q'] ?? ''));
        $cities = $city !== '' ? [$city] : [];

        $filters = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'salesman_id' => $salesmanId,
            'city' => $city,
            'category' => $category,
            'q' => $search,
        ];

        $error = null;
        $rows = [];
        $categories = [];
        try {
            $rows = $this->repository->rows($dateFrom, $dateTo, $cities, $salesmanId !== '' ? $salesmanId : null, $category, $search);
            $categories = $this->repository->categoryOptions($dateFrom, $dateTo, $cities, $salesmanId !== '' ? $salesmanId : null);
        } catch (Throwable $e) {
            Log::warning('item_based_sales.query_failed', ['message' => $e->getMessage()]);
            $error = 'The item sales report could not be loaded.';
        }

        $totals = $this->totals($rows);
        $salesmanLabel = 'All';
        $salesmen = [];
        $cityOptions = [];
        try {
            $salesmen = $this->visits->getSalesmanOptions();
            $cityOptions = $this->visits->getCityOptions();
            foreach ($salesmen as $salesman) {
                if ((string) ($salesman['id'] ?? '') === $salesmanId) {
                    $salesmanLabel = (string) ($salesman['name'] ?? $salesmanId);
                }
            }
        } catch (Throwable $e) {
            Log::warning('item_based_sales.options_failed', ['message' => $e->getMessage()]);
        }

        return [
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $totals,
            'categories' => $categories,
            'salesmanOptions' => $salesmen,
            'cityOptions' => $cityOptions,
            'salesmanLabel' => $salesmanLabel,
            'error' => $error,
        ];
    }

    /**
     * @param  list<object>  $rows
     * @return object
     */
    private function totals(array $rows): object
    {
        $totals = (object) [
            'sales_qty' => 0.0,
            'free_qty' => 0.0,
            'sales_discount' => 0.0,
            'sales_amount' => 0.0,
            'return_qty' => 0.0,
            'return_amount' => 0.0,
            'weight_total' => 0.0,
        ];
        foreach ($rows as $row) {
            $totals->sales_qty += (float) ($row->sales_qty ?? 0);
            $totals->free_qty += (float) ($row->free_qty ?? 0);
            $totals->sales_discount += (float) ($row->sales_discount ?? 0);
            $totals->sales_amount += (float) ($row->sales_amount ?? 0);
            $totals->return_qty += (float) ($row->return_qty ?? 0);
            $totals->return_amount += (float) ($row->return_amount ?? 0);
            $totals->weight_total += (float) ($row->weight_total ?? 0);
        }

        return $totals;
    }
}
