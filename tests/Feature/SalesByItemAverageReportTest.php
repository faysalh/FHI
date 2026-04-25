<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\SalesByItemAverageReportRepository;
use App\Repositories\VisitsReportRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class SalesByItemAverageReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sales_by_item_average_page_renders(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'category_name' => 'Fresh Chicken',
                'units_sold' => 10,
                'amount' => 100,
                'weight_total' => 5,
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(SalesByItemAverageReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getCategoryOptions')->andReturn(['Fresh Chicken', '(uncategorized)']);
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getAccountCityColumnName')->andReturn('fld_city');

        $this->app->instance(SalesByItemAverageReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->get('/reports/sales-item-average?date_from=2026-04-01&date_to=2026-04-19&working_days=2');

        $response->assertOk();
        $response->assertSee('Sales by item average');
        $response->assertSee('Fresh Chicken');
        $response->assertSee('Avg quantity / day (pcs)');
    }

    public function test_sales_by_item_average_items_endpoint_returns_json(): void
    {
        $repo = Mockery::mock(SalesByItemAverageReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getCategoryItems')->once()->andReturn([
            (object) [
                'item_name' => 'Chicken A',
                'units_sold' => 10,
                'amount' => 100,
                'weight_total' => 5,
            ],
        ]);
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getAccountCityColumnName')->andReturn('fld_city');

        $this->app->instance(SalesByItemAverageReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->get('/reports/sales-item-average/items?date_from=2026-04-01&date_to=2026-04-19&category=Fresh+Chicken');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('rows.0.item_name', 'Chicken A');
    }

    public function test_sales_by_item_average_pdf_export_returns_file(): void
    {
        $repo = Mockery::mock(SalesByItemAverageReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('exportRows')->once()->andReturn([
            (object) [
                'category_name' => 'Fresh Chicken',
                'item_name' => 'Chicken Breast',
                'units_sold' => 10,
                'amount' => 100,
                'weight_total' => 5,
            ],
        ]);
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getAccountCityColumnName')->andReturn('fld_city');

        $this->app->instance(SalesByItemAverageReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->get('/reports/sales-item-average/export/pdf?date_from=2026-04-01&date_to=2026-04-19&working_days=2');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
