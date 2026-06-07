<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvoicesReportRepository;
use App\Repositories\SalesReportRepository;
use App\Support\WorkingDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class DashboardLabMetricsService
{
    public function __construct(
        private readonly DashboardMetricsService $core,
        private readonly InvoicesReportRepository $invoices,
        private readonly SalesReportRepository $sales,
    ) {}

    /**
     * @param  list<string>  $cities
     * @return array<string, mixed>
     */
    public function build(array $cities, ?string $salesmanId, CarbonInterface $asOf): array
    {
        $core = $this->core->build($cities, $salesmanId, $asOf);

        $today = $asOf->toDateString();
        $monthFrom = $asOf->copy()->startOfMonth()->toDateString();
        $workingDaysElapsed = WorkingDays::countMonthToDateForProjection($asOf);
        $workingDaysInMonth = WorkingDays::countFullMonthForProjection($asOf);
        $workingDaysFridaysOnly = WorkingDays::countMonthToDateExcludingFridays($asOf);
        $calendarDaysElapsed = WorkingDays::calendarDaysElapsedInMonth($asOf);
        $daysInMonth = (int) $asOf->daysInMonth;
        $holidaysInMonth = WorkingDays::holidayDatesBetween(
            $asOf->copy()->startOfMonth(),
            $asOf->copy()->endOfMonth()
        );
        $holidaysElapsed = array_values(array_filter(
            $holidaysInMonth,
            static fn (string $d): bool => $d <= $asOf->toDateString()
        ));

        $salesmanIds = $this->salesmanIds($salesmanId);
        $invoiceSalesman = $salesmanIds === [] ? null : ($salesmanIds[0] ?? null);

        $compareDate = null;
        $compareInvoices = (object) ['invoice_count' => 0, 'quantity_total' => 0, 'invoice_amount' => 0];
        $compareSales = (object) ['units_sold' => 0, 'amount' => 0, 'weight_total' => 0];
        $monthInvoices = (object) ['invoice_count' => 0, 'quantity_total' => 0, 'invoice_amount' => 0];
        $prevMonthInvoices = (object) ['invoice_count' => 0, 'quantity_total' => 0, 'invoice_amount' => 0];
        $prevMonthFrom = null;
        $prevMonthTo = null;
        $prevMonthLabel = null;
        $prevMonthInvFrom = null;
        $prevMonthInvTo = null;
        $prevMonthInvLabel = null;
        $amountByCategory = [];
        $monthBySalesman = [];

        if ($cities !== []) {
            $compareDate = $this->sales->findLastDateWithSalesBefore($today, $cities, $salesmanIds);
            if ($compareDate !== null) {
                $compareInvoices = $this->invoices->getInvoiceSummary($compareDate, $compareDate, $cities, $invoiceSalesman);
                $compareSales = $this->sales->getMetricGrandTotals($compareDate, $compareDate, [], $cities, $salesmanIds);
            }
            $monthInvoices = $this->invoices->getInvoiceSummary($monthFrom, $today, $cities, $invoiceSalesman);

            $previousMonthRange = $this->previousCalendarMonthRange($asOf);
            $prevMonthFrom = $previousMonthRange['from'];
            $prevMonthTo = $previousMonthRange['to'];
            $prevMonthLabel = $previousMonthRange['label'];

            // Invoice deltas compare like-for-like: this month-to-date vs the SAME number of
            // working days into the previous month (e.g. Jun 1-7 vs May 1-7), so a partial
            // month is never compared against a full one. Sales pacing below keeps using the
            // full previous month on purpose (it is projection-based).
            $prevMonthSamePortion = $this->previousMonthSameWorkingDaysRange($asOf, $workingDaysElapsed);
            $prevMonthInvFrom = $prevMonthSamePortion['from'];
            $prevMonthInvTo = $prevMonthSamePortion['to'];
            $prevMonthInvLabel = $prevMonthSamePortion['label'];
            $prevMonthInvoices = $this->invoices->getInvoiceSummary($prevMonthInvFrom, $prevMonthInvTo, $cities, $invoiceSalesman);

            $amountByCategory = $this->sales->getAmountTotalsByCategory($monthFrom, $today, $cities, $salesmanIds);
            $monthBySalesman = $this->sales->getSalesAmountBySalesman($monthFrom, $today, $cities, $salesmanIds);
        }

        $compareLabel = $compareDate !== null
            ? Carbon::parse($compareDate)->format('j M Y')
            : null;

        $monthAmount = (float) ($core['monthSales']['amount'] ?? 0);
        $monthUnits = (float) ($core['monthSales']['units_sold'] ?? 0);
        $monthWeight = (float) ($core['monthSales']['weight_total'] ?? 0);
        $projectedAmount = $workingDaysElapsed > 0
            ? ($monthAmount / $workingDaysElapsed) * $workingDaysInMonth
            : 0;

        $todayInv = $core['todayInvoices'];
        $todayCount = (int) ($todayInv['invoice_count'] ?? 0);
        $todayAmount = (float) ($todayInv['invoice_amount'] ?? 0);

        $monthInvoiceCount = (int) ($monthInvoices->invoice_count ?? 0);
        $monthInvoiceAmount = (float) ($monthInvoices->invoice_amount ?? 0);
        $prevMonthInvoiceCount = (int) ($prevMonthInvoices->invoice_count ?? 0);
        $prevMonthInvoiceAmount = (float) ($prevMonthInvoices->invoice_amount ?? 0);
        $prevMonthSales = (object) ['units_sold' => 0, 'amount' => 0, 'weight_total' => 0];
        if ($cities !== [] && $prevMonthFrom !== null && $prevMonthTo !== null) {
            $prevMonthSales = $this->sales->getMetricGrandTotals($prevMonthFrom, $prevMonthTo, [], $cities, $salesmanIds);
        }
        $prevMonthSalesAmount = (float) ($prevMonthSales->amount ?? 0);
        $prevMonthSalesUnits = (float) ($prevMonthSales->units_sold ?? 0);
        $prevMonthSalesWeight = (float) ($prevMonthSales->weight_total ?? 0);

        $labDailyAvg = [
            'units' => $monthUnits / $workingDaysElapsed,
            'amount' => $monthAmount / $workingDaysElapsed,
            'weight' => $monthWeight / $workingDaysElapsed,
        ];

        $amountByCategoryMapped = array_map(function (object $row) use ($workingDaysElapsed): array {
            $amount = (float) ($row->amount_total ?? 0);
            $units = (float) ($row->units_sold ?? 0);

            return [
                'category_name' => (string) ($row->category_name ?? ''),
                'amount_total' => $amount,
                'units_sold' => $units,
                'amount_avg_daily' => $amount / $workingDaysElapsed,
            ];
        }, $amountByCategory);

        $weightByCategoryLab = array_map(function (array $row) use ($workingDaysElapsed): array {
            $row['weight_avg_daily'] = ((float) ($row['weight_total'] ?? 0)) / $workingDaysElapsed;

            return $row;
        }, $core['weightByCategory'] ?? []);

        $monthBySalesmanMapped = array_map(static function (object $row): array {
            return [
                'salesman_id' => (string) ($row->salesman_id ?? ''),
                'salesman_name' => (string) ($row->salesman_name ?? ''),
                'amount' => (float) ($row->amount ?? 0),
            ];
        }, $monthBySalesman);

        $comparisons = [
            'compare_date' => $compareDate,
            'compare_label' => $compareLabel,
            'invoice_count_delta' => $todayCount - (int) ($compareInvoices->invoice_count ?? 0),
            'invoice_amount_delta' => $todayAmount - (float) ($compareInvoices->invoice_amount ?? 0),
            'invoice_amount_pct' => $this->percentChange(
                (float) ($compareInvoices->invoice_amount ?? 0),
                $todayAmount
            ),
            'avg_per_invoice_today' => $todayCount > 0 ? $todayAmount / $todayCount : 0,
        ];

        $monthComparisons = [
            'compare_from' => $prevMonthInvFrom,
            'compare_to' => $prevMonthInvTo,
            'compare_label' => $prevMonthInvLabel,
            'invoice_count_delta' => $monthInvoiceCount - $prevMonthInvoiceCount,
            'invoice_amount_delta' => $monthInvoiceAmount - $prevMonthInvoiceAmount,
            'invoice_amount_pct' => $this->percentChange($prevMonthInvoiceAmount, $monthInvoiceAmount),
            'avg_per_invoice_month' => $monthInvoiceCount > 0 ? $monthInvoiceAmount / $monthInvoiceCount : 0,
        ];

        return array_merge($core, [
            'meta' => [
                'as_of' => $today,
                'comparison_date' => $compareDate,
                'comparison_label' => $compareLabel,
                'month_from' => $monthFrom,
                'month_to' => $today,
                'working_days_elapsed' => $workingDaysElapsed,
                'working_days_in_month' => $workingDaysInMonth,
                'working_days_elapsed_fridays_only' => $workingDaysFridaysOnly,
                'eid_holidays_in_month' => $holidaysInMonth,
                'eid_holidays_elapsed' => $holidaysElapsed,
                'calendar_days_elapsed' => $calendarDaysElapsed,
                'days_in_month' => $daysInMonth,
                'month_progress_pct' => round(($calendarDaysElapsed / max(1, $daysInMonth)) * 100, 1),
            ],
            'dailyAvg' => $labDailyAvg,
            'weightByCategory' => $weightByCategoryLab,
            'comparisonDay' => [
                'date' => $compareDate,
                'label' => $compareLabel,
                'invoices' => [
                    'invoice_count' => (int) ($compareInvoices->invoice_count ?? 0),
                    'quantity_total' => (float) ($compareInvoices->quantity_total ?? 0),
                    'invoice_amount' => (float) ($compareInvoices->invoice_amount ?? 0),
                ],
                'sales' => [
                    'units_sold' => (float) ($compareSales->units_sold ?? 0),
                    'amount' => (float) ($compareSales->amount ?? 0),
                    'weight_total' => (float) ($compareSales->weight_total ?? 0),
                ],
            ],
            'comparisons' => $comparisons,
            'monthInvoices' => [
                'invoice_count' => $monthInvoiceCount,
                'quantity_total' => (float) ($monthInvoices->quantity_total ?? 0),
                'invoice_amount' => $monthInvoiceAmount,
            ],
            'monthComparisons' => $monthComparisons,
            'monthSalesComparisons' => [
                'compare_label' => $prevMonthLabel,
                'previous' => [
                    'amount' => $prevMonthSalesAmount,
                    'units_sold' => $prevMonthSalesUnits,
                    'weight_total' => $prevMonthSalesWeight,
                ],
                'amount_delta' => $monthAmount - $prevMonthSalesAmount,
                'amount_pct' => $this->percentChange($prevMonthSalesAmount, $monthAmount),
                'units_delta' => $monthUnits - $prevMonthSalesUnits,
                'units_pct' => $this->percentChange($prevMonthSalesUnits, $monthUnits),
                'weight_delta' => $monthWeight - $prevMonthSalesWeight,
                'weight_pct' => $this->percentChange($prevMonthSalesWeight, $monthWeight),
                'projected_vs_previous_pct' => $this->percentChange($prevMonthSalesAmount, $projectedAmount),
            ],
            'pacing' => [
                'projected_month_amount' => $projectedAmount,
                'daily_run_rate_amount' => $labDailyAvg['amount'],
                'remaining_working_days' => max(0, $workingDaysInMonth - $workingDaysElapsed),
            ],
            'amountByCategory' => $amountByCategoryMapped,
            'monthSalesBySalesman' => $monthBySalesmanMapped,
            'insights' => $this->buildInsights(
                $core,
                $comparisons,
                $compareLabel,
                $monthBySalesmanMapped,
                $amountByCategoryMapped,
                $projectedAmount,
                $workingDaysElapsed,
                $workingDaysInMonth
            ),
        ]);
    }

    /**
     * @param  list<array{salesman_name: string, amount: float}>  $monthBySalesman
     * @param  list<array{category_name: string, amount_total: float}>  $amountByCategory
     * @return list<string>
     */
    private function buildInsights(
        array $core,
        array $comparisons,
        ?string $compareLabel,
        array $monthBySalesman,
        array $amountByCategory,
        float $projectedAmount,
        int $workingDaysElapsed,
        int $workingDaysInMonth
    ): array {
        $insights = [];

        $todaySales = $core['salesBySalesman'] ?? [];
        $todayTotal = array_sum(array_map(static fn (array $r): float => (float) ($r['amount'] ?? 0), $todaySales));
        if ($todayTotal > 0 && $todaySales !== []) {
            $top = $todaySales[0];
            $share = round(((float) ($top['amount'] ?? 0) / $todayTotal) * 100);
            $insights[] = 'Today, '.($top['salesman_name'] ?? 'one salesman').' leads with '.display_number($share).'% of sales amount ('.display_number($top['amount'] ?? 0).').';
        }

        $monthAmount = (float) ($core['monthSales']['amount'] ?? 0);
        if ($monthAmount > 0 && $monthBySalesman !== []) {
            $topMonth = $monthBySalesman[0];
            $monthShare = round(((float) ($topMonth['amount'] ?? 0) / $monthAmount) * 100);
            $insights[] = 'Month-to-date, '.($topMonth['salesman_name'] ?? 'one salesman').' accounts for '.display_number($monthShare).'% of amount.';
        }

        if ($amountByCategory !== []) {
            $topCat = $amountByCategory[0];
            $insights[] = 'Top category by amount: '.($topCat['category_name'] ?? '').' ('.display_number($topCat['amount_total'] ?? 0).' MTD).';
        }

        if ($projectedAmount > 0 && $monthAmount > 0) {
            $insights[] = 'At the current run rate (Fridays and Eid holidays excluded), month amount could reach about '.display_number($projectedAmount).' ('.display_number($workingDaysElapsed).' of '.display_number($workingDaysInMonth).' business days in the month).';
        }

        $delta = (int) ($comparisons['invoice_count_delta'] ?? 0);
        if ($delta !== 0 && $compareLabel !== null) {
            $insights[] = 'Invoice count is '.($delta > 0 ? 'up' : 'down').' '.display_number(abs($delta)).' versus '.$compareLabel.' (last day with sales).';
        }

        if ($insights === []) {
            $insights[] = 'No activity yet for the selected filters — try another salesman or confirm governorate cities are configured.';
        }

        return $insights;
    }

    private function percentChange(float $previous, float $current): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Full previous calendar month (not the same partial day-range in the current month).
     *
     * @return array{from: string, to: string, label: string}
     */
    private function previousCalendarMonthRange(CarbonInterface $asOf): array
    {
        $start = $asOf->copy()->subMonthNoOverflow()->startOfMonth();
        $end = $asOf->copy()->subMonthNoOverflow()->endOfMonth();

        $from = $start->toDateString();
        $to = $end->toDateString();
        $label = $start->format('F Y');
        if ($from !== $to) {
            $label = $start->format('j M').' – '.$end->format('j M Y');
        }

        return [
            'from' => $from,
            'to' => $to,
            'label' => $label,
        ];
    }

    /**
     * Previous month from its 1st through the date of its Nth business day, where N is the
     * number of working days elapsed this month. Gives a like-for-like comparison window
     * (same number of working days, Fridays and Eid holidays excluded).
     *
     * @return array{from: string, to: string, label: string}
     */
    private function previousMonthSameWorkingDaysRange(CarbonInterface $asOf, int $workingDaysElapsed): array
    {
        $prevAnchor = $asOf->copy()->subMonthNoOverflow();
        $start = $prevAnchor->copy()->startOfMonth();
        $end = WorkingDays::nthBusinessDayOfMonthForProjection($prevAnchor, $workingDaysElapsed);

        $from = $start->toDateString();
        $to = $end->toDateString();
        $label = $from === $to
            ? $start->format('j M Y')
            : $start->format('j M').' – '.$end->format('j M Y');

        return [
            'from' => $from,
            'to' => $to,
            'label' => $label,
        ];
    }

    /**
     * @return list<string>
     */
    private function salesmanIds(?string $salesmanId): array
    {
        return $this->sales->normalizeSalesmanIds($salesmanId !== null && $salesmanId !== '' ? [$salesmanId] : []);
    }
}
