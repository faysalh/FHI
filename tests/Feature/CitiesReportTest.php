<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CitiesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use Mockery;
use Tests\TestCase;

class CitiesReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_cities_report_table_renders_with_totals(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
        $visits->shouldReceive('getAccountCityColumnName')->andReturn(null);

        $repo = Mockery::mock(CitiesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('getReport')->once()->andReturn([
            (object) [
                'units_sold' => 10,
                'amount' => 100.5,
                'weight_total' => 2,
            ],
        ]);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);
        $gov->shouldReceive('getGovernorateById')->andReturn(null);

        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesReportRepository::class, $repo);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get('/reports/cities?group_by_client=0&panel=table');

        $response->assertOk();
        $response->assertSee('Cities sales report');
        $response->assertDontSee('Saved governorates');
        $response->assertSee('Period totals');
    }

    public function test_cities_charts_panel_renders(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn(['Test City']);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
        $visits->shouldReceive('getAccountCityColumnName')->andReturn('fld_city');

        $repo = Mockery::mock(CitiesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('getSalesOverTimeChartSeries')->once()->andReturn([
            (object) [
                'sale_date' => '2026-04-01',
                'amount' => 500,
                'units_sold' => 10,
                'weight_total' => 1,
                'customer_count' => 3,
                'invoice_count' => 5,
            ],
        ]);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);
        $gov->shouldReceive('getGovernorateById')->andReturn(null);

        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesReportRepository::class, $repo);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get('/reports/cities?panel=charts');

        $response->assertOk();
        $response->assertSee('City sales charts');
        $response->assertSee('Sales over time');
        $response->assertSee('city-sales-time-chart');
        $response->assertSee('Export chart PDF');
        $response->assertSee('Show series:');
    }

    public function test_cities_export_chart_pdf_returns_file(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
        $visits->shouldReceive('getAccountCityColumnName')->andReturn(null);

        $repo = Mockery::mock(CitiesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('getSalesOverTimeChartSeries')->once()->andReturn([
            (object) [
                'sale_date' => '2026-04-01',
                'amount' => 500,
                'units_sold' => 10,
                'weight_total' => 1,
                'customer_count' => 3,
                'invoice_count' => 5,
            ],
        ]);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);
        $gov->shouldReceive('getGovernorateById')->andReturn(null);

        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesReportRepository::class, $repo);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get('/reports/cities/export/chart-pdf?date_from=2026-04-01&date_to=2026-04-01');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_cities_export_chart_pdf_respects_chart_show(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
        $visits->shouldReceive('getAccountCityColumnName')->andReturn(null);

        $repo = Mockery::mock(CitiesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('getSalesOverTimeChartSeries')->once()->andReturn([
            (object) [
                'sale_date' => '2026-04-01',
                'amount' => 500,
                'units_sold' => 10,
                'weight_total' => 1,
                'customer_count' => 3,
                'invoice_count' => 5,
            ],
        ]);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);
        $gov->shouldReceive('getGovernorateById')->andReturn(null);

        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesReportRepository::class, $repo);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get(
            '/reports/cities/export/chart-pdf?date_from=2026-04-01&date_to=2026-04-01&chart_show[]=amount&chart_show[]=customer_count'
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
