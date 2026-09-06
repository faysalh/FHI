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
                'invoice_id' => '1001',
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
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')->once()->andReturn((object) [
            'quantity' => 12,
            'amount' => 0,
            'weight_total' => 0,
        ]);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn(['Erbil']);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
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
        $response->assertSee('sales invoices');
        $response->assertSee('Total (all matching filters)', false);
        $response->assertSee('Client One');
        $response->assertSee('Delivered');
    }

    public function test_invoice_search_loads_invoice_without_date_restriction(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'invoice_id' => '1001',
                'invoice_no' => '8842',
                'document_date' => '2026-01-15',
                'client_code' => 'C-100',
                'client_name' => 'Old Invoice Client',
                'city_name' => 'Erbil',
                'storage_name' => 'Main Store',
                'quantity' => 5,
                'delivery_status' => 'Not delivered',
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn([]);
        $repo->shouldReceive('resolveInvoiceIdsByNumberSearch')
            ->once()
            ->with('8842')
            ->andReturn(['1001']);
        $repo->shouldReceive('getReport')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                [],
                [],
                null,
                null,
                ['1001'],
                1,
                250,
                false
            )
            ->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')
            ->once()
            ->with(Mockery::any(), Mockery::any(), [], [], null, null, ['1001'], false)
            ->andReturn((object) ['quantity' => 5, 'amount' => 0, 'weight_total' => 0]);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
        $teams = Mockery::mock(DeliveriesTeamSqliteService::class);
        $teams->shouldReceive('listDrivers')->andReturn([]);
        $teams->shouldReceive('listCompanions')->andReturn([]);
        $teams->shouldReceive('listDailyTeamsForDate')->andReturn([]);
        $teams->shouldReceive('listDailyTeamsByDateRange')->andReturn([]);
        $teams->shouldReceive('assignmentsByInvoiceIds')->andReturn([]);

        $this->app->instance(DeliveriesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(DeliveriesTeamSqliteService::class, $teams);

        $response = $this->get('/reports/deliveries?date_from=2026-04-01&date_to=2026-04-20&invoice_search=8842');

        $response->assertOk();
        $response->assertSee('Old Invoice Client');
        $response->assertSee('date range ignored', false);
    }

    public function test_team_filter_loads_all_assigned_invoices_without_date_restriction(): void
    {
        $paginator = new LengthAwarePaginator(items: [], total: 0, perPage: 25, currentPage: 1);

        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn([]);
        $repo->shouldReceive('getReport')
            ->once()
            ->with(
                '2026-04-01',
                '2026-04-20',
                [],
                [],
                null,
                null,
                ['999'],
                1,
                250,
                false
            )
            ->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')
            ->once()
            ->with('2026-04-01', '2026-04-20', [], [], null, null, ['999'], false)
            ->andReturn((object) ['quantity' => 0, 'amount' => 0, 'weight_total' => 0]);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
        $teams = Mockery::mock(DeliveriesTeamSqliteService::class);
        $teams->shouldReceive('listDrivers')->andReturn([]);
        $teams->shouldReceive('listCompanions')->andReturn([]);
        $teams->shouldReceive('listDailyTeamsForDate')->andReturn([]);
        $teams->shouldReceive('listDailyTeamsByDateRange')->andReturn([]);
        $teams->shouldReceive('invoiceIdsForTeam')->once()->with(5)->andReturn(['999']);
        $teams->shouldReceive('assignmentsByInvoiceIds')->andReturn([]);

        $this->app->instance(DeliveriesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(DeliveriesTeamSqliteService::class, $teams);

        $response = $this->get('/reports/deliveries?date_from=2026-04-01&date_to=2026-04-20&team_id=5');

        $response->assertOk();
    }

    public function test_batch_assignment_matches_invoices_outside_date_range(): void
    {
        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('findInvoicesByInvoiceNumbersForBatch')
            ->once()
            ->with(['INV-100'])
            ->andReturn([
                (object) [
                    'invoice_id' => '1001',
                    'invoice_no' => 'INV-100',
                    'document_date' => '2026-01-15',
                ],
            ]);

        $teams = Mockery::mock(DeliveriesTeamSqliteService::class);
        $teams->shouldReceive('listAllAssignedInvoiceIds')->never();
        $teams->shouldReceive('assignmentsByInvoiceIds')
            ->once()
            ->with(['1001'])
            ->andReturn([]);
        $teams->shouldReceive('assignInvoiceTeam')
            ->once()
            ->with('1001', '2026-01-15', 3);

        $extractor = Mockery::mock(\App\Services\DeliveryInvoicePdfExtractor::class);
        $extractor->shouldReceive('extractInvoiceNumbersFromUpload')->once()->andReturn(['INV-100']);

        $this->app->instance(DeliveriesReportRepository::class, $repo);
        $this->app->instance(DeliveriesTeamSqliteService::class, $teams);
        $this->app->instance(\App\Services\DeliveryInvoicePdfExtractor::class, $extractor);

        $response = $this->post('/reports/deliveries/batch-assign?tab=batch-assignment&team_date=2026-04-20', [
            'team_id' => 3,
            'batch_pdf' => \Illuminate\Http\UploadedFile::fake()->create('invoices.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Batch assignment completed.');
    }

    public function test_clear_team_assignments_removes_all_invoices_for_team(): void
    {
        $teams = Mockery::mock(DeliveriesTeamSqliteService::class);
        $teams->shouldReceive('clearTeamAssignments')
            ->once()
            ->with(3)
            ->andReturn(39);

        $this->app->instance(DeliveriesTeamSqliteService::class, $teams);

        $response = $this->post('/reports/deliveries/clear-team-assignments?tab=batch-assignment', [
            'team_id' => 3,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Removed 39 invoice assignment(s) for the selected team.');
    }

    public function test_delivery_status_can_be_updated(): void
    {
        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('updateDeliveryStatus')
            ->once()
            ->with('1001', 'not_delivered')
            ->andReturn(3);

        $this->app->instance(DeliveriesReportRepository::class, $repo);

        $response = $this->post('/reports/deliveries/status?date_from=2026-04-01&date_to=2026-04-20', [
            'invoice_id' => '1001',
            'current_status' => 'not_delivered',
        ]);

        $response->assertRedirect('/reports/deliveries?date_from=2026-04-01&date_to=2026-04-20');
        $response->assertSessionHas('status', 'Delivery status changed to delivered.');
    }

    public function test_deliveries_pdf_export_returns_file(): void
    {
        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('exportRows')->once()->andReturn([
            (object) [
                'invoice_id' => '1001',
                'invoice_no' => '8842',
                'document_date' => '2026-04-20',
                'client_code' => 'C-100',
                'client_name' => 'Client One',
                'city_name' => 'Erbil',
                'storage_name' => 'Main Store',
                'quantity' => 12,
                'delivery_status' => 'Delivered',
            ],
        ]);

        $this->app->instance(DeliveriesReportRepository::class, $repo);

        $response = $this->get('/reports/deliveries/export/pdf?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_deliveries_items_pdf_export_returns_file(): void
    {
        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('exportItemRows')->once()->andReturn([
            (object) [
                'category_name' => 'Poultry',
                'item_name' => 'Whole chicken',
                'quantity' => 40,
                'weight_total' => 80,
            ],
        ]);

        $assembly = Mockery::mock(\App\Services\ReportAssemblyPriorityService::class);
        $assembly->shouldReceive('sortRows')
            ->once()
            ->andReturnUsing(static fn (array $rows): array => $rows);

        $this->app->instance(DeliveriesReportRepository::class, $repo);
        $this->app->instance(\App\Services\ReportAssemblyPriorityService::class, $assembly);

        $response = $this->get('/reports/deliveries/export/items/pdf?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
