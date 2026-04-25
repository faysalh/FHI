<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SalesByItemAverageReportExport;
use App\Http\Requests\SalesByItemAverageReportRequest;
use App\Repositories\SalesByItemAverageReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\NumberDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SalesByItemAverageReportController extends Controller
{
    public function __construct(
        private readonly SalesByItemAverageReportRepository $repository,
        private readonly VisitsReportRepository $visitsRepository
    ) {
    }

    public function index(SalesByItemAverageReportRequest $request): View
    {
        $today = Carbon::now()->toDateString();
        $input = array_merge([
            'date_from' => $today,
            'date_to' => $today,
            'per_page' => 25,
            'q' => '',
            'working_days' => 0,
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $perPage = (int) ($input['per_page'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $q = trim((string) ($input['q'] ?? ''));
        $excludeCategory = trim((string) ($input['exclude_category'] ?? ''));
        $workingDays = max(0, (int) ($input['working_days'] ?? 0));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );
        $cityOptions = $this->cityOptionsForPicker();
        $hasCityColumn = $this->visitsRepository->getAccountCityColumnName() !== null;
        $categoryOptions = [];
        try {
            $categoryOptions = $this->repository->getCategoryOptions(
                $dateFrom,
                $dateTo,
                $q !== '' ? $q : null,
                $cities
            );
        } catch (Throwable $e) {
            Log::warning('Sales by item average category options failed.', ['message' => $e->getMessage()]);
        }

        try {
            $rows = $this->repository->getReport(
                $dateFrom,
                $dateTo,
                $q !== '' ? $q : null,
                $excludeCategory !== '' ? $excludeCategory : null,
                $cities,
                $page,
                $perPage
            );
        } catch (Throwable $e) {
            Log::error('Sales by item average report failed.', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'message' => $e->getMessage(),
            ]);

            return view('reports.sales-item-average.index', [
                'rows' => null,
                'filters' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'per_page' => $perPage,
                    'q' => $q,
                    'exclude_category' => $excludeCategory,
                    'cities' => $cities,
                    'working_days' => $workingDays,
                ],
                'errorMessage' => 'Unable to load sales by item average report. Check logs and try again.',
                'workingDaysDivisor' => $workingDays > 0 ? $workingDays : null,
                'cityOptions' => $cityOptions,
                'categoryOptions' => $categoryOptions,
                'hasCityColumn' => $hasCityColumn,
            ]);
        }

        return view('reports.sales-item-average.index', [
            'rows' => $rows,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'per_page' => $perPage,
                'q' => $q,
                'cities' => $cities,
                'working_days' => $workingDays,
                'exclude_category' => $excludeCategory,
            ],
            'errorMessage' => null,
            'workingDaysDivisor' => $workingDays > 0 ? $workingDays : null,
            'cityOptions' => $cityOptions,
            'categoryOptions' => $categoryOptions,
            'hasCityColumn' => $hasCityColumn,
        ]);
    }

    public function categoryItems(SalesByItemAverageReportRequest $request): JsonResponse
    {
        $input = array_merge([
            'q' => '',
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $category = trim((string) ($input['category'] ?? ''));
        $excludeCategory = trim((string) ($input['exclude_category'] ?? ''));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        if ($category === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Category is required.',
                'rows' => [],
            ], 422);
        }

        try {
            $rows = $this->repository->getCategoryItems(
                $dateFrom,
                $dateTo,
                $category,
                $excludeCategory !== '' ? $excludeCategory : null,
                $cities
            );
        } catch (Throwable $e) {
            Log::error('Sales by item average category drilldown failed.', ['message' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'message' => 'Could not load item breakdown.',
                'rows' => [],
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'rows' => array_map(static function (object $row): array {
                return [
                    'item_name' => (string) ($row->item_name ?? ''),
                    'units_sold' => (float) ($row->units_sold ?? 0),
                    'amount' => (float) ($row->amount ?? 0),
                    'weight_total' => (float) ($row->weight_total ?? 0),
                    'storage_balance' => (float) ($row->storage_balance ?? 0),
                ];
            }, $rows),
        ]);
    }

    public function exportPdf(SalesByItemAverageReportRequest $request): Response|RedirectResponse
    {
        $input = array_merge([
            'q' => '',
            'working_days' => 0,
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $excludeCategory = trim((string) ($input['exclude_category'] ?? ''));
        $workingDays = max(0, (int) ($input['working_days'] ?? 0));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        try {
            $rows = $this->repository->exportRows(
                $dateFrom,
                $dateTo,
                $q !== '' ? $q : null,
                $category !== '' ? $category : null,
                $excludeCategory !== '' ? $excludeCategory : null,
                $cities
            );
        } catch (Throwable $e) {
            Log::error('Sales by item average PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales-item-average.index', $request->query()))
                ->with('error', 'Could not export PDF.');
        }

        $pdf = Pdf::loadView('reports.sales-item-average.pdf', [
            'rows' => $rows,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'q' => $q,
            'category' => $category,
            'cities' => $cities,
            'includeItemBreakdown' => true,
            'workingDays' => $workingDays,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('sales-by-item-average-'.$dateFrom.'-'.$dateTo.'.pdf');
    }

    public function exportCsv(SalesByItemAverageReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = array_merge([
            'q' => '',
            'working_days' => 0,
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $excludeCategory = trim((string) ($input['exclude_category'] ?? ''));
        $workingDays = max(0, (int) ($input['working_days'] ?? 0));
        $cities = $this->repository->normalizeCities(
            is_array($input['cities'] ?? null) ? $input['cities'] : []
        );

        try {
            $rows = $this->repository->exportRows(
                $dateFrom,
                $dateTo,
                $q !== '' ? $q : null,
                $category !== '' ? $category : null,
                $excludeCategory !== '' ? $excludeCategory : null,
                $cities
            );
        } catch (Throwable $e) {
            Log::error('Sales by item average CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales-item-average.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        return Excel::download(
            new SalesByItemAverageReportExport($rows, $workingDays),
            'sales-by-item-average-'.$dateFrom.'-'.$dateTo.'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public static function avgValue(float $value, ?int $workingDaysDivisor): string
    {
        if ($workingDaysDivisor === null || $workingDaysDivisor <= 0) {
            return '—';
        }

        return NumberDisplay::format($value / $workingDaysDivisor);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function cityOptionsForPicker(): array
    {
        $cities = $this->visitsRepository->getCityOptions();
        $out = [];
        foreach ($cities as $c) {
            if ($c !== '') {
                $out[] = ['id' => $c, 'name' => $c];
            }
        }

        return $out;
    }
}
