<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\ItemBasedSalesReportRepository;
use App\Repositories\VisitsReportRepository;
use Mockery;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sales_report_is_one_row_per_item(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $repo = Mockery::mock(ItemBasedSalesReportRepository::class);
        $repo->shouldReceive('rows')->once()->andReturn([
            (object) [
                'item_code' => 'W1',
                'item_name' => 'Whole chicken',
                'category_name' => 'Chicken',
                'sales_qty' => 10,
                'free_qty' => 1,
                'sales_discount' => 50,
                'sales_amount' => 1000,
                'return_qty' => 2,
                'return_amount' => 200,
                'weight_total' => 12,
            ],
        ]);
        $repo->shouldReceive('categoryOptions')->once()->andReturn(['Chicken']);
        $this->app->instance(ItemBasedSalesReportRepository::class, $repo);

        $response = $this->get('/reports/sales?date_from=2026-11-01&date_to=2026-11-22');

        $response->assertOk();
        $response->assertSee('Sales report by item');
        $response->assertSee('Whole chicken');
        $response->assertSee('Chicken');
        $response->assertDontSee('By client');
        $response->assertDontSee('Sales by item');
    }

    public function test_old_sales_by_item_url_opens_the_same_report(): void
    {
        $response = $this->get('/reports/sales-by-item?date_from=2026-11-01&date_to=2026-11-22');

        $response->assertRedirect('/reports/sales?date_from=2026-11-01&date_to=2026-11-22');
    }
}
