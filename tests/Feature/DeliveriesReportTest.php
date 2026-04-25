<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\DeliveriesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\DeliveriesTeamSqliteService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class DeliveriesReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_deliveries_page_renders(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'document_date' => '2026-04-20',
                'client_code' => 'C-100',
                'client_name' => 'Client One',
                'city_name' => 'Erbil',
                'storage_name' => 'Main Store',
                'quantity' => 12,
                'delivery_status' => 'Delivered',
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn(['Erbil']);
        $teams = Mockery::mock(DeliveriesTeamSqliteService::class);
        $teams->shouldReceive('listDrivers')->andReturn([]);
        $teams->shouldReceive('listCompanions')->andReturn([]);
        $teams->shouldReceive('listDailyTeamsForDate')->andReturn([]);
        $teams->shouldReceive('listDailyTeamsByDateRange')->andReturn([]);
        $teams->shouldReceive('assignmentsByInvoiceIds')->andReturn([]);

        $this->app->instance(DeliveriesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(DeliveriesTeamSqliteService::class, $teams);

        $response = $this->get('/reports/deliveries?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertSee('Deliveries report');
        $response->assertSee('Client One');
        $response->assertSee('Delivered');
    }

    public function test_deliveries_pdf_export_returns_file(): void
    {
        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('exportRows')->once()->andReturn([
            (object) [
                'document_date' => '2026-04-20',
                'client_code' => 'C-100',
                'client_name' => 'Client One',
                'city_name' => 'Erbil',
                'storage_name' => 'Main Store',
                'quantity' => 12,
                'delivery_status' => 'Delivered',
            ],
        ]);
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $teams = Mockery::mock(DeliveriesTeamSqliteService::class);
        $teams->shouldReceive('assignmentsByInvoiceIds')->andReturn([]);

        $this->app->instance(DeliveriesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(DeliveriesTeamSqliteService::class, $teams);

        $response = $this->get('/reports/deliveries/export/pdf?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}

