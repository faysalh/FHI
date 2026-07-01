<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\InvoicesReportRepository;
use App\Repositories\SalesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Services\DashboardGovernorateService;
use App\Services\DashboardLabMetricsService;
use App\Services\DashboardMetricsService;
use App\Support\WorkingDays;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class DashboardLabTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_lab_dashboard_page_renders(): void
    {
        $this->registerMocks();

        $response = $this->get('/reports/dashboard-lab');

        $response->assertOk();
        $response->assertSee('Dashboard', false);
        $response->assertDontSee('Experimental preview');
        $response->assertDontSee('production dashboard');
        $response->assertSee('Weight by category');
        $response->assertSee('id="lab_governorate_id"', false);
        $response->assertSee('id="lab_as_of_date"', false);
        $response->assertSee('Insights');
        $response->assertSee('Erbil');
        $response->assertSee('Duhok');
    }

    public function test_lab_metrics_returns_extended_payload(): void
    {
        $this->registerMocks();

        $response = $this->getJson('/reports/dashboard-lab/metrics');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonStructure([
            'insights',
            'pacing' => ['projected_month_amount'],
            'amountByCategory',
            'monthInvoices' => ['invoice_count', 'quantity_total', 'invoice_amount'],
            'monthComparisons' => ['compare_label', 'invoice_count_delta', 'invoice_amount_delta'],
            'monthSalesComparisons' => ['compare_label', 'amount_delta', 'amount_pct', 'projected_vs_previous_pct'],
            'monthSalesBySalesman',
            'comparisons' => ['compare_date', 'compare_label'],
            'comparisonDay' => ['date', 'invoices'],
            'meta' => ['working_days_elapsed', 'comparison_date'],
        ]);
    }

    public function test_lab_metrics_uses_selected_governorate(): void
    {
        $this->registerMocks();

        $response = $this->getJson('/reports/dashboard-lab/metrics?saved_governorate_id=2');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('governorate_label', 'Duhok');
    }

    public function test_month_invoice_comparison_uses_same_working_days_of_previous_month(): void
    {
        $this->registerMocks();
        config(['reporting.non_working_holidays' => ['2026' => []], 'reporting.non_working_holidays_extra' => '']);

        Carbon::setTestNow('2026-06-15 12:00:00');

        try {
            $now = Carbon::now();
            $elapsed = WorkingDays::countMonthToDateForProjection($now);
            $expectedTo = WorkingDays::nthBusinessDayOfMonthForProjection(
                $now->copy()->subMonthNoOverflow(),
                $elapsed
            )->toDateString();

            $response = $this->getJson('/reports/dashboard-lab/metrics');
        } finally {
            Carbon::setTestNow();
        }

        $response->assertOk();
        // Like-for-like: previous month start through the SAME number of working days,
        // never the full previous calendar month (which would be 2026-05-31).
        $response->assertJsonPath('monthComparisons.compare_from', '2026-05-01');
        $response->assertJsonPath('monthComparisons.compare_to', $expectedTo);
        $this->assertNotSame('2026-05-31', $expectedTo);
    }

    public function test_month_sales_pace_still_uses_full_previous_calendar_month(): void
    {
        $this->registerMocks();

        Carbon::setTestNow('2026-06-15 12:00:00');

        try {
            $response = $this->getJson('/reports/dashboard-lab/metrics');
        } finally {
            Carbon::setTestNow();
        }

        $response->assertOk();
        $response->assertJsonPath('monthSalesComparisons.compare_label', '1 May – 31 May 2026');
    }

    public function test_metrics_honors_as_of_date_when_historical_dates_enabled(): void
    {
        $this->registerMocks();
        config(['reporting.dashboard_lab.historical_dates_enabled' => true]);

        Carbon::setTestNow('2026-06-20 12:00:00');

        try {
            $response = $this->getJson('/reports/dashboard-lab/metrics?as_of_date=2026-06-10');
        } finally {
            Carbon::setTestNow();
        }

        $response->assertOk();
        $response->assertJsonPath('meta.as_of', '2026-06-10');
        $response->assertJsonPath('meta.is_live', false);
        $response->assertJsonPath('meta.day_section_label', '10 Jun 2026');
    }

    public function test_metrics_ignores_as_of_date_when_historical_dates_disabled(): void
    {
        $this->registerMocks();
        config(['reporting.dashboard_lab.historical_dates_enabled' => false]);

        Carbon::setTestNow('2026-06-20 12:00:00');

        try {
            $response = $this->getJson('/reports/dashboard-lab/metrics?as_of_date=2026-06-10');
        } finally {
            Carbon::setTestNow();
        }

        $response->assertOk();
        $response->assertJsonPath('meta.as_of', '2026-06-20');
        $response->assertJsonPath('meta.is_live', true);
    }

    public function test_live_query_param_forces_today_even_with_as_of_date(): void
    {
        $this->registerMocks();
        config(['reporting.dashboard_lab.historical_dates_enabled' => true]);

        Carbon::setTestNow('2026-06-20 12:00:00');

        try {
            $response = $this->getJson('/reports/dashboard-lab/metrics?as_of_date=2026-06-10&live=1');
        } finally {
            Carbon::setTestNow();
        }

        $response->assertOk();
        $response->assertJsonPath('meta.as_of', '2026-06-20');
        $response->assertJsonPath('meta.is_live', true);
    }

    public function test_page_hides_date_picker_when_historical_dates_disabled(): void
    {
        $this->registerMocks();
        config(['reporting.dashboard_lab.historical_dates_enabled' => false]);

        $response = $this->get('/reports/dashboard-lab');

        $response->assertOk();
        $response->assertDontSee('id="lab_as_of_date"', false);
        $response->assertSee('Live only');
    }

    private function registerMocks(): void
    {
        $governorates = Mockery::mock(CitiesGovernorateSqliteService::class);
        $governorates->shouldReceive('listGovernorates')->andReturn([
            (object) ['id' => 1, 'name' => 'Erbil', 'governorate_city' => 'Erbil'],
            (object) ['id' => 2, 'name' => 'Duhok', 'governorate_city' => 'Duhok'],
        ]);
        $governorates->shouldReceive('getGovernorateById')->andReturnUsing(function (int $id): ?array {
            return match ($id) {
                1 => [
                    'id' => 1,
                    'name' => 'Erbil',
                    'governorate_city' => 'Erbil',
                    'members' => ['Erbil'],
                ],
                2 => [
                    'id' => 2,
                    'name' => 'Duhok',
                    'governorate_city' => 'Duhok',
                    'members' => ['Duhok'],
                ],
                default => null,
            };
        });

        $invoices = Mockery::mock(InvoicesReportRepository::class);
        $invoices->shouldReceive('getInvoiceSummary')->andReturn((object) [
            'invoice_count' => 5,
            'quantity_total' => 100,
            'invoice_amount' => 5000,
        ]);

        $sales = Mockery::mock(SalesReportRepository::class);
        $sales->shouldReceive('normalizeCities')->andReturnUsing(function (array $cities): array {
            return $cities;
        });
        $sales->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $sales->shouldReceive('getMetricGrandTotals')->andReturn((object) [
            'units_sold' => 1000,
            'amount' => 50000,
            'weight_total' => 8000,
        ]);
        $sales->shouldReceive('getWeightTotalsByCategory')->andReturn([]);
        $sales->shouldReceive('getAmountTotalsByCategory')->andReturn([]);
        $sales->shouldReceive('getSalesAmountBySalesman')->andReturn([
            (object) ['salesman_id' => 'a', 'salesman_name' => 'Ali', 'amount' => 3000],
        ]);
        $sales->shouldReceive('findLastDateWithSalesBefore')->andReturn(
            now()->subDays(2)->toDateString()
        );

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);

        $core = new DashboardMetricsService($invoices, $sales);
        $lab = new DashboardLabMetricsService($core, $invoices, $sales);

        $this->app->instance(CitiesGovernorateSqliteService::class, $governorates);
        $this->app->instance(InvoicesReportRepository::class, $invoices);
        $this->app->instance(SalesReportRepository::class, $sales);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(DashboardLabMetricsService::class, $lab);
    }
}
