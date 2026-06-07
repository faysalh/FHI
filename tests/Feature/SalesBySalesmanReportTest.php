<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\SalesBySalesmanReportRepository;
use App\Repositories\VisitsReportRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class SalesBySalesmanReportTest extends TestCase
{
    private const SAMPLE_SALESMAN_UUID = '11111111-1111-1111-1111-111111111111';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_page_renders_without_salesman(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getSalesmanOptions')->once()->andReturn([
            ['id' => self::SAMPLE_SALESMAN_UUID, 'name' => 'Salesman A'],
        ]);

        $repo = Mockery::mock(SalesBySalesmanReportRepository::class);
        $repo->shouldReceive('getResolvedClientPriceGroupColumn')->once()->andReturn(null);
        $repo->shouldReceive('normalizeSalesmanId')->once()->with('')->andReturn(null);

        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(SalesBySalesmanReportRepository::class, $repo);

        $response = $this->get('/reports/sales-by-salesman');

        $response->assertOk();
        $response->assertSee('Sales by salesman');
        $response->assertSee('Select a salesman');
    }

    public function test_page_renders_table_when_salesman_selected(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getSalesmanOptions')->once()->andReturn([
            ['id' => self::SAMPLE_SALESMAN_UUID, 'name' => 'Salesman A'],
        ]);

        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'client_code' => 'C1',
                'client_name' => 'Client One',
                'client_price_group' => 'وكيل 2',
                'invoice_count' => 4,
                'quantity_sold' => 12.5,
                'amount' => 1500.25,
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(SalesBySalesmanReportRepository::class);
        $repo->shouldReceive('getResolvedClientPriceGroupColumn')->once()->andReturn('fld_sale_price_no');
        $repo->shouldReceive('normalizeSalesmanId')->once()->with(self::SAMPLE_SALESMAN_UUID)->andReturn(self::SAMPLE_SALESMAN_UUID);
        $repo->shouldReceive('getReport')
            ->once()
            ->with('2026-04-01', '2026-04-30', self::SAMPLE_SALESMAN_UUID, 1, 250)
            ->andReturn($paginator);
        $repo->shouldReceive('getGrandTotals')
            ->once()
            ->with('2026-04-01', '2026-04-30', self::SAMPLE_SALESMAN_UUID)
            ->andReturn((object) [
                'sum_invoice_count' => 4,
                'sum_quantity_sold' => 12.5,
                'sum_amount' => 1500.25,
            ]);

        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(SalesBySalesmanReportRepository::class, $repo);

        $response = $this->get('/reports/sales-by-salesman?date_from=2026-04-01&date_to=2026-04-30&salesman_id='.self::SAMPLE_SALESMAN_UUID);

        $response->assertOk();
        $response->assertSee('Client One');
        $response->assertSee('وكيل 2');
        $response->assertSee('Number of invoices');
        $response->assertSee('Quantity of sales');
        $response->assertSee('Total (all clients)');
    }
}
