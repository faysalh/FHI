<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvoicesReportRepository;
use App\Repositories\SalesReportRepository;
use App\Support\WorkingDays;
use Carbon\CarbonInterface;
use stdClass;

class DashboardMetricsService
{
    public function __construct(
        private readonly InvoicesReportRepository $invoices,
        private readonly SalesReportRepository $sales,
    ) {}

    /**
     * @param  list<string>  $cities
     * @return array<string, mixed>
     */
    public function build(array $cities, ?string $salesmanId, CarbonInterface $asOf): array
    {
        $today = $asOf->toDateString();
        $monthFrom = $asOf->copy()->startOfMonth()->toDateString();
        $workingDays = WorkingDays::countMonthToDateForProjection($asOf);

        $salesmanIds = $this->salesmanFilter($salesmanId);
        $invoiceSalesman = $salesmanIds === [] ? null : ($salesmanIds[0] ?? null);

        $todayInvoices = $this->emptyInvoiceSummary();
        $monthSales = $this->emptySalesTotals();
        $weightByCategory = [];
        $salesBySalesman = [];

        if ($cities !== []) {
            $todayInvoices = $this->invoices->getInvoiceSummary($today, $today, $cities, $invoiceSalesman);
            $monthSales = $this->sales->getMetricGrandTotals($monthFrom, $today, [], $cities, $salesmanIds);
            $weightByCategory = $this->sales->getWeightTotalsByCategory($monthFrom, $today, $cities, $salesmanIds);
            $salesBySalesman = $this->sales->getSalesAmountBySalesman($today, $today, $cities, $salesmanIds);
        }

        $monthAmount = (float) ($monthSales->amount ?? 0);
        $monthUnits = (float) ($monthSales->units_sold ?? 0);
        $monthWeight = (float) ($monthSales->weight_total ?? 0);

        return [
            'todayInvoices' => [
                'invoice_count' => (int) ($todayInvoices->invoice_count ?? 0),
                'quantity_total' => (float) ($todayInvoices->quantity_total ?? 0),
                'invoice_amount' => (float) ($todayInvoices->invoice_amount ?? 0),
            ],
            'monthSales' => [
                'units_sold' => $monthUnits,
                'amount' => $monthAmount,
                'weight_total' => $monthWeight,
            ],
            'dailyAvg' => [
                'units' => $monthUnits / $workingDays,
                'amount' => $monthAmount / $workingDays,
                'weight' => $monthWeight / $workingDays,
            ],
            'weightByCategory' => array_map(function (object $row) use ($workingDays): array {
                $weight = (float) ($row->weight_total ?? 0);

                return [
                    'category_name' => (string) ($row->category_name ?? ''),
                    'weight_total' => $weight,
                    'weight_avg_daily' => $weight / $workingDays,
                ];
            }, $weightByCategory),
            'salesBySalesman' => array_map(static function (object $row): array {
                return [
                    'salesman_id' => (string) ($row->salesman_id ?? ''),
                    'salesman_name' => (string) ($row->salesman_name ?? ''),
                    'amount' => (float) ($row->amount ?? 0),
                ];
            }, $salesBySalesman),
        ];
    }

    /**
     * @return list<string>
     */
    private function salesmanFilter(?string $salesmanId): array
    {
        $id = $this->sales->normalizeSalesmanIds($salesmanId !== null && $salesmanId !== '' ? [$salesmanId] : []);

        return $id;
    }

    private function emptyInvoiceSummary(): stdClass
    {
        return (object) [
            'invoice_count' => 0,
            'quantity_total' => 0,
            'invoice_amount' => 0,
        ];
    }

    private function emptySalesTotals(): stdClass
    {
        return (object) [
            'units_sold' => 0,
            'amount' => 0,
            'weight_total' => 0,
        ];
    }
}
