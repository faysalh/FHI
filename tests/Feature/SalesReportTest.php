<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\SalesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function bindSalesFilterDependencies(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $governorates = Mockery::mock(CitiesGovernorateSqliteService::class);
        $governorates->shouldReceive('listGovernorates')->andReturn([]);
        $this->app->instance(CitiesGovernorateSqliteService::class, $governorates);
    }

    public function test_sales_report_page_renders_with_totals(): void
    {
        $this->bindSalesFilterDependencies();

        $repo = Mockery::mock(SalesReportRepository::class);
        $repo->shouldReceive('normalizeCustomerAccountIds')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getCustomerAccountOptions')->andReturn([]);
        $repo->shouldReceive('getMetricGrandTotals')->andReturn((object) [
            'units_sold' => 100,
            'amount' => 5000.5,
            'weight_total' => 42.25,
        ]);
        $repo->shouldReceive('getReport')->once()->andReturn([
            (object) [
                'units_sold' => 100,
                'amount' => 5000.5,
                'weight_total' => 42.25,
            ],
        ]);

        $this->app->instance(SalesReportRepository::class, $repo);

        $response = $this->get('/reports/sales?group_by_client=0');

        $response->assertOk();
        $response->assertSee('Sales report');
        $response->assertSee('Period totals', false);
        $response->assertSee('Quantity (pcs)', false);
        $response->assertSee('100', false);
        $response->assertSee('5,000.5', false);
        $response->assertSee('Weight (kg)', false);
        $response->assertSee('42.25', false);
    }

    public function test_sales_report_by_client_renders_paginator(): void
    {
        $this->bindSalesFilterDependencies();

        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'client_account_id' => '11111111-1111-1111-1111-111111111111',
                'client_code' => 'C1',
                'client_name' => 'Test Client',
                'units_sold' => 10,
                'amount' => 100,
                'weight_total' => 5,
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1,
        );

        $repo = Mockery::mock(SalesReportRepository::class);
        $repo->shouldReceive('normalizeCustomerAccountIds')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getCustomerAccountOptions')->andReturn([]);
        $repo->shouldReceive('getMetricGrandTotals')->andReturn((object) [
            'units_sold' => 10,
            'amount' => 100,
            'weight_total' => 5,
        ]);
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);

        $this->app->instance(SalesReportRepository::class, $repo);

        $response = $this->get('/reports/sales?group_by_client=1');

        $response->assertOk();
        $response->assertSee('Test Client');
        $response->assertSee('drilldown-trigger', false);
    }

    public function test_sales_client_items_endpoint_returns_json(): void
    {
        $repo = Mockery::mock(SalesReportRepository::class);
        $repo->shouldReceive('getClientItemBreakdown')->once()->andReturn([
            (object) [
                'item_category' => 'Wings',
                'item_name' => 'Item A',
                'units_sold' => 3,
                'amount' => 30,
                'weight_total' => 1.5,
            ],
        ]);

        $this->app->instance(SalesReportRepository::class, $repo);

        $response = $this->get('/reports/sales/client-items?date_from=2026-04-01&date_to=2026-04-19&client_account_id=11111111-1111-1111-1111-111111111111');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('rows.0.item_name', 'Item A');
    }

    public function test_sales_category_totals_with_items_renders_item_rows(): void
    {
        $this->bindSalesFilterDependencies();

        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'chicken_category' => 'Wings',
                'item_name' => 'Wing A',
                'units_sold' => 12,
                'amount' => 240,
                'weight_total' => 6,
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1,
        );

        $repo = Mockery::mock(SalesReportRepository::class);
        $repo->shouldReceive('normalizeCustomerAccountIds')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getCustomerAccountOptions')->andReturn([]);
        $repo->shouldReceive('getMetricGrandTotals')->andReturn((object) [
            'units_sold' => 12,
            'amount' => 240,
            'weight_total' => 6,
        ]);
        $repo->shouldReceive('getChickenCategoryItemBreakdown')->once()->andReturn($paginator);
        $repo->shouldReceive('exportChickenCategoryRows')->once()->andReturn([
            (object) [
                'chicken_category' => 'Wings',
                'units_sold' => 12,
                'amount' => 240,
                'weight_total' => 6,
            ],
        ]);

        $this->app->instance(SalesReportRepository::class, $repo);

        $response = $this->get('/reports/sales?breakdown=1&breakdown_items=1');

        $response->assertOk();
        $response->assertSee('Include items', false);
        $response->assertSee('Wing A');
        $response->assertSee('Item name', false);
        $response->assertSee('By category', false);
        $response->assertSee('Wings — subtotal', false);
    }
}
