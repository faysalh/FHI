<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\VisitsReportExport;
use App\Http\Requests\VisitsReportRequest;
use App\Repositories\VisitsReportRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class VisitsReportController extends Controller
{
    public function __construct(
        private readonly VisitsReportRepository $repository
    ) {
    }

    public function index(VisitsReportRequest $request): View
    {
        $today = Carbon::now()->toDateString();
        $defaults = [
            'date_from' => $today,
            'date_to' => $today,
            'per_page' => 25,
        ];
        $input = array_merge($defaults, $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $perPage = (int) ($input['per_page'] ?? 25);
        $page = (int) $request->input('page', 1);

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
                $perPage
            );

            return view('reports.visits.index', [
                'rows' => $rows,
                'salesmen' => $this->repository->getSalesmanOptions(),
                'cityOptions' => $this->repository->getCityOptions(),
                'monthSegments' => $monthSegments,
                'multiMonth' => $multiMonth,
                'filters' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'cities' => $cities,
                    'salesman_id' => $salesmanId,
                    'per_page' => $perPage,
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
                ],
                'errorMessage' => 'Unable to load visits report. Check storage/logs/laravel.log for the SQL error. If it is not about the city column, verify store document tables and Identifier rules (salesman/client joins). You can set REPORTING_ACCOUNT_CITY_COLUMN to the exact column name on dbo.tbl_accounting_accounts.',
            ]);
        }
    }

    public function exportPdf(VisitsReportRequest $request): Response|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];

        $cities = $request->input('cities', []);
        $cities = is_array($cities) ? array_values(array_filter(array_map('strval', $cities))) : [];

        $salesmanId = $request->input('salesman_id');
        $salesmanId = is_string($salesmanId) && trim($salesmanId) !== '' ? trim($salesmanId) : null;

        $monthSegments = $this->repository->monthSegmentsInRange($dateFrom, $dateTo);
        $multiMonth = count($monthSegments) > 1;

        try {
            $rows = $this->repository->getVisitsForExport($dateFrom, $dateTo, $cities, $salesmanId);

            $pdf = Pdf::loadView('reports.visits.pdf', [
                'rows' => $rows,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'cities' => $cities,
                'salesmanId' => $salesmanId,
                'monthSegments' => $monthSegments,
                'multiMonth' => $multiMonth,
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
                ->with('error', 'Could not export PDF. If the report is large, narrow the date range or filters. Check storage/logs/laravel.log for details.');
        }
    }

    public function exportCsv(VisitsReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = $request->validated();
        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];

        $cities = $request->input('cities', []);
        $cities = is_array($cities) ? array_values(array_filter(array_map('strval', $cities))) : [];

        $salesmanId = $request->input('salesman_id');
        $salesmanId = is_string($salesmanId) && trim($salesmanId) !== '' ? trim($salesmanId) : null;

        $monthSegments = $this->repository->monthSegmentsInRange($dateFrom, $dateTo);
        $multiMonth = count($monthSegments) > 1;

        try {
            $rows = $this->repository->getVisitsForExport($dateFrom, $dateTo, $cities, $salesmanId);
        } catch (Throwable $e) {
            Log::error('Visits CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.visits.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        $filename = 'visits-'.$dateFrom.'-'.$dateTo.'.csv';

        return Excel::download(
            new VisitsReportExport($rows, $monthSegments, $multiMonth),
            $filename,
            \Maatwebsite\Excel\Excel::CSV
        );
    }
}
