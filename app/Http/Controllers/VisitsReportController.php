<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\VisitsReportExport;
use App\Http\Requests\VisitsReportRequest;
use App\Repositories\VisitsReportRepository;
use App\Support\ReportCityPickerOptions;
use App\Support\VisitsReportGrouping;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class VisitsReportController extends Controller
{
    public function __construct(
        private readonly VisitsReportRepository $repository
    ) {}

    public function index(VisitsReportRequest $request): View
    {
        $today = Carbon::now()->toDateString();
        $defaults = [
            'date_from' => $today,
            'date_to' => $today,
            'per_page' => 250,
            'sort_by_city' => false,
            'show_month_sales' => false,
        ];
        $input = array_merge($defaults, $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $perPage = (int) ($input['per_page'] ?? 250);
        $page = (int) $request->input('page', 1);
        $sortByCity = (bool) ($input['sort_by_city'] ?? false);
        $showMonthSales = (bool) ($input['show_month_sales'] ?? false);

        $cities = $request->input('cities', []);
        $cities = is_array($cities) ? array_values(array_filter(array_map('strval', $cities))) : [];

        $salesmanId = $request->input('salesman_id');
        $salesmanId = is_string($salesmanId) && trim($salesmanId) !== '' ? trim($salesmanId) : null;

        $monthSegments = $this->repository->monthSegmentsInRange($dateFrom, $dateTo);
        $multiMonth = count($monthSegments) > 1;

        try {
            $rows = $this->repository->paginateVisits(
                $dateFrom,
                $dateTo,
                $cities,
                $salesmanId,
                $page,
                $perPage,
                $sortByCity,
                $showMonthSales
            );

            return view('reports.visits.index', [
                'rows' => $rows,
                'salesmen' => $this->repository->getSalesmanOptions(),
                'cityOptions' => $this->repository->getCityOptions(),
                'cityOptionsForPicker' => ReportCityPickerOptions::fromCityNames($this->repository->getCityOptions()),
                'monthSegments' => $monthSegments,
                'multiMonth' => $multiMonth,
                'filters' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'cities' => $cities,
                    'salesman_id' => $salesmanId,
                    'per_page' => $perPage,
                    'sort_by_city' => $sortByCity,
                    'show_month_sales' => $showMonthSales,
                ],
                'errorMessage' => null,
            ]);
        } catch (Throwable $e) {
            Log::error('Visits report failed.', [
                'message' => $e->getMessage(),
            ]);

            return view('reports.visits.index', [
                'rows' => new LengthAwarePaginator([], 0, $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]),
                'salesmen' => [],
                'cityOptions' => [],
                'monthSegments' => [],
                'multiMonth' => false,
                'filters' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'cities' => $cities,
                    'salesman_id' => $salesmanId,
                    'per_page' => $perPage,
                    'sort_by_city' => $sortByCity,
                    'show_month_sales' => $showMonthSales,
                ],
                'errorMessage' => 'Unable to load visits report. Check storage/logs/laravel.log for the SQL error. If it is not about the city column, verify store document tables and Identifier rules (salesman/client joins). You can set REPORTING_ACCOUNT_CITY_COLUMN to the exact column name on dbo.tbl_accounting_accounts.',
                'cityOptionsForPicker' => [],
            ]);
        }
    }

    public function exportPdf(VisitsReportRequest $request): Response|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $sortByCity = (bool) ($input['sort_by_city'] ?? false);
        $showMonthSales = (bool) ($input['show_month_sales'] ?? false);

        $cities = $request->input('cities', []);
        $cities = is_array($cities) ? array_values(array_filter(array_map('strval', $cities))) : [];

        $salesmanId = $request->input('salesman_id');
        $salesmanId = is_string($salesmanId) && trim($salesmanId) !== '' ? trim($salesmanId) : null;

        $monthSegments = $this->repository->monthSegmentsInRange($dateFrom, $dateTo);
        $multiMonth = count($monthSegments) > 1;

        try {
            $pdfRowCap = VisitsReportRepository::MAX_PDF_EXPORT_ROWS;
            $rows = $this->repository->getVisitsForPdfExport($dateFrom, $dateTo, $cities, $salesmanId, $sortByCity, $showMonthSales);
            $rows = VisitsReportGrouping::sortForExport($rows, $monthSegments, $multiMonth, $sortByCity);
            $pdfTruncated = count($rows) >= $pdfRowCap;
            $salesmanName = $this->resolveSelectedSalesmanName($salesmanId, $rows);

            @ini_set('memory_limit', '1024M');

            $pdf = Pdf::loadView('reports.visits.pdf', [
                'rows' => $rows,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'cities' => $cities,
                'salesmanName' => $salesmanName,
                'monthSegments' => $monthSegments,
                'multiMonth' => $multiMonth,
                'sortByCity' => $sortByCity,
                'showMonthSales' => $showMonthSales,
                'pdfTruncated' => $pdfTruncated,
                'pdfRowCap' => $pdfRowCap,
                ...\App\Support\ReportPdfBranding::viewData(),
            ])->setPaper('a4', 'landscape');

            $filename = 'visits-'.$dateFrom.'-'.$dateTo.'.pdf';

            return $pdf->download($filename);
        } catch (Throwable $e) {
            Log::error('Visits PDF export failed.', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return redirect()
                ->to(route('reports.visits.index', $request->query()))
                ->with('error', 'Could not export PDF. Try fewer clients (narrow cities or salesman), turn off monthly sales columns, or use CSV for the full list. Check storage/logs/laravel.log for details.');
        }
    }

    public function exportCsv(VisitsReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $sortByCity = (bool) ($input['sort_by_city'] ?? false);
        $showMonthSales = (bool) ($input['show_month_sales'] ?? false);

        $cities = $request->input('cities', []);
        $cities = is_array($cities) ? array_values(array_filter(array_map('strval', $cities))) : [];

        $salesmanId = $request->input('salesman_id');
        $salesmanId = is_string($salesmanId) && trim($salesmanId) !== '' ? trim($salesmanId) : null;

        $monthSegments = $this->repository->monthSegmentsInRange($dateFrom, $dateTo);
        $multiMonth = count($monthSegments) > 1;

        try {
            $rows = $this->repository->getVisitsForExport($dateFrom, $dateTo, $cities, $salesmanId, $sortByCity, $showMonthSales);
            $rows = VisitsReportGrouping::sortForExport($rows, $monthSegments, $multiMonth, $sortByCity);
        } catch (Throwable $e) {
            Log::error('Visits CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.visits.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        $filename = 'visits-'.$dateFrom.'-'.$dateTo.'.csv';

        return Excel::download(
            new VisitsReportExport($rows, $monthSegments, $multiMonth, $sortByCity, $showMonthSales),
            $filename,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @param  list<object>  $rows
     */
    private function resolveSelectedSalesmanName(?string $salesmanId, array $rows): ?string
    {
        $salesmanId = is_string($salesmanId) ? trim($salesmanId) : '';
        if ($salesmanId === '') {
            return null;
        }

        foreach ($this->repository->getSalesmanOptions() as $salesman) {
            $id = trim((string) ($salesman['id'] ?? ''));
            if ($id !== $salesmanId) {
                continue;
            }

            $name = trim((string) ($salesman['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $name = trim((string) ($row->salesman_name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
