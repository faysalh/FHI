<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\SalesReportExport;
use App\Http\Requests\SalesReportRequest;
use App\Repositories\SalesReportRepository;
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

class SalesReportController extends Controller
{
    private const EXPORT_ROW_CAP = 10000;

    public function __construct(
        private readonly SalesReportRepository $repository
    ) {
    }

    public function index(SalesReportRequest $request): View
    {
        $today = Carbon::now()->toDateString();
        $defaults = [
            'date_from' => $today,
            'date_to' => $today,
            'group_by_client' => true,
            'per_page' => 25,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'q' => '',
            'customer_account_ids' => [],
        ];

        $input = array_merge($defaults, $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $groupByClient = (bool) ($input['group_by_client'] ?? false);
        $perPage = (int) ($input['per_page'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $breakdown = (bool) ($input['breakdown'] ?? false);
        $breakdownByClient = (bool) ($input['breakdown_by_client'] ?? false);
        $q = trim((string) ($input['q'] ?? ''));
        $customerAccountIds = $this->repository->normalizeCustomerAccountIds(
            is_array($input['customer_account_ids'] ?? null) ? $input['customer_account_ids'] : []
        );

        $filters = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'group_by_client' => $groupByClient,
            'per_page' => $perPage,
            'breakdown' => $breakdown,
            'breakdown_by_client' => $breakdownByClient,
            'q' => $q,
            'customer_account_ids' => $customerAccountIds,
        ];

        $customerOptions = $this->repository->getCustomerAccountOptions();

        try {
            if ($breakdownByClient) {
                $result = $this->repository->getChickenCategoryBreakdownByClient(
                    $dateFrom,
                    $dateTo,
                    $q !== '' ? $q : null,
                    $page,
                    $perPage,
                    $customerAccountIds
                );

                return view('reports.sales.index', [
                    'mode' => 'by_category_by_client',
                    'rows' => $result,
                    'totals' => null,
                    'filters' => $filters,
                    'customerOptions' => $customerOptions,
                    'errorMessage' => null,
                ]);
            }

            if ($breakdown) {
                $result = $this->repository->getChickenCategoryBreakdown(
                    $dateFrom,
                    $dateTo,
                    $q !== '' ? $q : null,
                    $page,
                    $perPage,
                    $customerAccountIds
                );

                return view('reports.sales.index', [
                    'mode' => 'by_category',
                    'rows' => $result,
                    'totals' => null,
                    'filters' => $filters,
                    'customerOptions' => $customerOptions,
                    'errorMessage' => null,
                ]);
            }

            $result = $this->repository->getReport(
                $dateFrom,
                $dateTo,
                $groupByClient,
                $page,
                $perPage,
                $customerAccountIds
            );

            if ($groupByClient && $result instanceof LengthAwarePaginator) {
                return view('reports.sales.index', [
                    'mode' => 'by_client',
                    'rows' => $result,
                    'totals' => null,
                    'filters' => $filters,
                    'customerOptions' => $customerOptions,
                    'errorMessage' => null,
                ]);
            }

            $totals = is_array($result) && isset($result[0]) ? $result[0] : null;

            return view('reports.sales.index', [
                'mode' => 'totals',
                'rows' => null,
                'totals' => $totals,
                'filters' => $filters,
                'customerOptions' => $customerOptions,
                'errorMessage' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Sales report failed.', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'group_by_client' => $groupByClient,
                'breakdown' => $breakdown,
                'breakdown_by_client' => $breakdownByClient,
                'message' => $exception->getMessage(),
            ]);

            return view('reports.sales.index', [
                'mode' => $breakdownByClient
                    ? 'by_category_by_client'
                    : ($breakdown ? 'by_category' : ($groupByClient ? 'by_client' : 'totals')),
                'rows' => null,
                'totals' => null,
                'filters' => $filters,
                'customerOptions' => $customerOptions,
                'errorMessage' => 'Unable to load sales report. Check logs and try again.',
            ]);
        }
    }

    public function exportPdf(SalesReportRequest $request): Response|RedirectResponse
    {
        $input = array_merge([
            'group_by_client' => true,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'q' => '',
            'customer_account_ids' => [],
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $customerAccountIds = $this->repository->normalizeCustomerAccountIds(
            is_array($input['customer_account_ids'] ?? null) ? $input['customer_account_ids'] : []
        );

        $exportMode = $this->resolveExportMode($input);

        try {
            $rows = $this->fetchExportRows($exportMode, $dateFrom, $dateTo, $q, $customerAccountIds);
        } catch (Throwable $e) {
            Log::error('Sales PDF export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales.index', $request->query()))
                ->with('error', 'Could not export PDF. Check logs and try a narrower date range or fewer filters.');
        }

        $pdf = Pdf::loadView('reports.sales.export-pdf', [
            'mode' => $exportMode,
            'rows' => $rows,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'q' => $q,
            'modeLabel' => $this->exportModeLabel($exportMode),
            'customerLabel' => $this->customerFilterLabel($customerAccountIds),
            'exportCap' => self::EXPORT_ROW_CAP,
        ])->setPaper('a4', $exportMode === 'by_category_by_client' ? 'landscape' : 'portrait');

        $filename = 'sales-'.$dateFrom.'-'.$dateTo.'.pdf';

        return $pdf->download($filename);
    }

    public function exportCsv(SalesReportRequest $request): BinaryFileResponse|RedirectResponse
    {
        $input = array_merge([
            'group_by_client' => true,
            'breakdown' => false,
            'breakdown_by_client' => false,
            'q' => '',
            'customer_account_ids' => [],
        ], $request->validated());

        $dateFrom = (string) $input['date_from'];
        $dateTo = (string) $input['date_to'];
        $q = trim((string) ($input['q'] ?? ''));
        $customerAccountIds = $this->repository->normalizeCustomerAccountIds(
            is_array($input['customer_account_ids'] ?? null) ? $input['customer_account_ids'] : []
        );

        $exportMode = $this->resolveExportMode($input);

        try {
            $rows = $this->fetchExportRows($exportMode, $dateFrom, $dateTo, $q, $customerAccountIds);
        } catch (Throwable $e) {
            Log::error('Sales CSV export failed.', ['message' => $e->getMessage()]);

            return redirect()
                ->to(route('reports.sales.index', $request->query()))
                ->with('error', 'Could not export CSV.');
        }

        $filename = 'sales-'.$dateFrom.'-'.$dateTo.'.csv';

        return Excel::download(
            new SalesReportExport($rows, $exportMode),
            $filename,
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return 'totals'|'by_client'|'by_category'|'by_category_by_client'
     */
    private function resolveExportMode(array $input): string
    {
        if (! empty($input['breakdown_by_client'])) {
            return 'by_category_by_client';
        }
        if (! empty($input['breakdown'])) {
            return 'by_category';
        }
        if (! empty($input['group_by_client'])) {
            return 'by_client';
        }

        return 'totals';
    }

    /**
     * @return list<stdClass>
     */
    private function fetchExportRows(
        string $exportMode,
        string $dateFrom,
        string $dateTo,
        string $q,
        array $customerAccountIds
    ): array {
        $qOrNull = $q !== '' ? $q : null;

        return match ($exportMode) {
            'by_category_by_client' => $this->repository->exportChickenCategoryByClientRows(
                $dateFrom,
                $dateTo,
                $qOrNull,
                $customerAccountIds
            ),
            'by_category' => $this->repository->exportChickenCategoryRows(
                $dateFrom,
                $dateTo,
                $qOrNull,
                $customerAccountIds
            ),
            'by_client' => $this->repository->exportReportRows(
                $dateFrom,
                $dateTo,
                true,
                $customerAccountIds
            ),
            default => $this->repository->exportReportRows(
                $dateFrom,
                $dateTo,
                false,
                $customerAccountIds
            ),
        };
    }

    /**
     * @param  list<string>  $customerAccountIds
     */
    private function customerFilterLabel(array $customerAccountIds): string
    {
        if ($customerAccountIds === []) {
            return '';
        }

        $byId = collect($this->repository->getCustomerAccountOptions())->keyBy('id');
        $parts = [];
        foreach ($customerAccountIds as $id) {
            $row = $byId->get($id);
            $parts[] = is_array($row) ? ($row['name'] ?? $id) : $id;
        }

        return implode('; ', $parts);
    }

    private function exportModeLabel(string $mode): string
    {
        return match ($mode) {
            'by_category_by_client' => 'Category by client',
            'by_category' => 'Category breakdown',
            'by_client' => 'By client',
            'totals' => 'Period totals',
            default => $mode,
        };
    }
}
