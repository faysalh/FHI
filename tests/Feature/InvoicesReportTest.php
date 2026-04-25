<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\InvoicesReportRepository;
use App\Repositories\VisitsReportRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class InvoicesReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_invoices_page_renders(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'invoice_id' => 'inv-1',
                'invoice_no' => '1001',
                'invoice_date' => '2026-04-20',
                'created_at' => '2026-04-20 10:00:00',
                'client_code' => 'C-100',
                'client_name' => 'Client One',
                'city_name' => 'Erbil',
                'store_name' => 'Main Store',
                'salesman_name' => 'Salesman A',
                'quantity_total' => 15,
                'invoice_amount' => 250000,
                'client_due_amount' => 100000,
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(InvoicesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getStoreOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->once()->andReturn(['Erbil']);
        $visits->shouldReceive('getSalesmanOptions')->once()->andReturn([
            ['id' => 'sm-1', 'name' => 'Salesman A'],
        ]);

        $this->app->instance(InvoicesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->get('/reports/invoices?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertSee('Invoices report');
        $response->assertSee('Client One');
        $response->assertSee('1001');
    }

    public function test_invoices_items_endpoint_returns_json(): void
    {
        $repo = Mockery::mock(InvoicesReportRepository::class);
        $repo->shouldReceive('getInvoiceItems')->once()->andReturn([
            (object) [
                'item_name' => 'Wings',
                'quantity' => 5,
                'amount' => 20000,
            ],
        ]);

        $this->app->instance(InvoicesReportRepository::class, $repo);

        $response = $this->get('/reports/invoices/items?invoice_id=inv-1');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('rows.0.item_name', 'Wings');
    }
}

