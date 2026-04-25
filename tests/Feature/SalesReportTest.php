<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\SalesReportRepository;
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

    public function test_sales_report_page_renders_with_totals(): void
    {
        $repo = Mockery::mock(SalesReportRepository::class);
        $repo->shouldReceive('normalizeCustomerAccountIds')->andReturn([]);
        $repo->shouldReceive('getCustomerAccountOptions')->andReturn([]);
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
        $response->assertSee('Period totals');
        $response->assertSee('Quantity (pcs): 100');
        $response->assertSee('5,000.5');
        $response->assertSee('Weight (kg): 42.25');
    }

    public function test_sales_report_by_client_renders_paginator(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
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
        $repo->shouldReceive('getCustomerAccountOptions')->andReturn([]);
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);

        $this->app->instance(SalesReportRepository::class, $repo);

        $response = $this->get('/reports/sales?group_by_client=1');

        $response->assertOk();
        $response->assertSee('Test Client');
    }
}
