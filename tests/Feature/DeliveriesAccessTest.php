<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\DeliveriesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\DeliveriesTeamSqliteService;
use App\Services\ReportsUsersSqliteService;
use App\Support\DeliveriesReportAccess;
use App\Support\ReportAuthSession;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class DeliveriesAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_user_without_status_edit_permission_cannot_update_delivery_status(): void
    {
        ReportAuthSession::login(7, 'limited', false, ['deliveries'], new DeliveriesReportAccess(
            canEditStatus: false,
            defaultStorage: 'Warehouse A',
            canFilterStorage: false,
        ));

        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('updateDeliveryStatus')->never();
        $this->app->instance(DeliveriesReportRepository::class, $repo);

        $response = $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/reports/deliveries/status?date_from=2026-04-01&date_to=2026-04-20', [
            'invoice_id' => '1001',
            'current_status' => 'not_delivered',
        ]);

        $response->assertRedirect('/reports/deliveries?date_from=2026-04-01&date_to=2026-04-20');
        $response->assertSessionHas('error', 'You do not have permission to change delivery status.');
    }

    public function test_locked_default_storage_is_applied_when_storage_filter_disabled(): void
    {
        ReportAuthSession::login(8, 'storage-only', false, ['deliveries'], new DeliveriesReportAccess(
            canFilterDate: true,
            canFilterCity: false,
            canFilterStorage: false,
            canFilterSalesman: false,
            canFilterStatus: false,
            canEditStatus: false,
            defaultStorage: 'Warehouse A',
        ));

        $paginator = new LengthAwarePaginator(items: [], total: 0, perPage: 250, currentPage: 1);

        $repo = Mockery::mock(DeliveriesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $repo->shouldReceive('getStorageOptions')->andReturn(['Warehouse A', 'Warehouse B']);
        $repo->shouldReceive('getReport')
            ->once()
            ->with(
                '2026-04-01',
                '2026-04-20',
                [],
                [],
                'Warehouse A',
                null,
                null,
                1,
                250,
                true
            )
            ->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')
            ->once()
            ->with('2026-04-01', '2026-04-20', [], [], 'Warehouse A', null, null, true)
            ->andReturn((object) ['quantity' => 0, 'amount' => 0, 'weight_total' => 0]);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);

        $teams = Mockery::mock(DeliveriesTeamSqliteService::class);
        $teams->shouldReceive('listDrivers')->andReturn([]);
        $teams->shouldReceive('listCompanions')->andReturn([]);
        $teams->shouldReceive('listDailyTeamsForDate')->andReturn([]);
        $teams->shouldReceive('listDailyTeamsByDateRange')->andReturn([]);

        $this->app->instance(DeliveriesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(DeliveriesTeamSqliteService::class, $teams);

        $response = $this->get('/reports/deliveries?date_from=2026-04-01&date_to=2026-04-20&storage=Warehouse+B');

        $response->assertOk();
        $response->assertSee('Warehouse A (fixed for your account)', false);
    }

    public function test_deliveries_access_is_saved_for_report_user(): void
    {
        $users = Mockery::mock(ReportsUsersSqliteService::class);
        $users->shouldReceive('ensureReady');
        $users->shouldReceive('createUser')
            ->once()
            ->withArgs(function (string $username, string $password, bool $isSuperAdmin, array $keys, ?DeliveriesReportAccess $access): bool {
                return $username === 'driver1'
                    && $password === 'secret12'
                    && $isSuperAdmin === false
                    && $keys === ['deliveries']
                    && $access instanceof DeliveriesReportAccess
                    && $access->canFilterDate === true
                    && $access->canFilterStorage === false
                    && $access->defaultStorage === 'Warehouse A';
            })
            ->andReturn(12);

        $deliveriesRepo = Mockery::mock(DeliveriesReportRepository::class);
        $deliveriesRepo->shouldReceive('getStorageOptions')->andReturn(['Warehouse A']);

        $this->app->instance(ReportsUsersSqliteService::class, $users);
        $this->app->instance(DeliveriesReportRepository::class, $deliveriesRepo);

        $response = $this->post(route('reports.users.store'), [
            'username' => 'driver1',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'report_keys' => ['deliveries'],
            'deliveries_can_filter_date' => '1',
            'deliveries_default_storage' => 'Warehouse A',
        ]);

        $response->assertRedirect(route('reports.users.index'));
        $response->assertSessionHas('status', 'User created.');
    }

    public function test_save_daily_team_redirect_preserves_restricted_user_filters_from_post_body(): void
    {
        ReportAuthSession::login(9, 'deliveries-limited', false, ['deliveries'], new DeliveriesReportAccess(
            canFilterDate: true,
            canFilterCity: false,
            canFilterStorage: false,
            canFilterSalesman: true,
            canFilterStatus: true,
            canEditStatus: false,
        ));

        $teams = Mockery::mock(DeliveriesTeamSqliteService::class);
        $teams->shouldReceive('addDailyTeam')
            ->once()
            ->with('2026-06-07', 3, 5)
            ->andReturn(42);

        $this->app->instance(DeliveriesTeamSqliteService::class, $teams);

        $response = $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/reports/deliveries/setup/daily-team', [
                'team_date' => '2026-06-07',
                'driver_id' => 3,
                'companion_id' => 5,
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-20',
                'delivery_status' => 'not_delivered',
                'salesman_ids' => [12, 15],
                'tab' => 'daily-teams',
            ]);

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertNotNull($target);
        $this->assertStringContainsString('date_from=2026-04-01', (string) $target);
        $this->assertStringContainsString('date_to=2026-04-20', (string) $target);
        $this->assertStringContainsString('delivery_status=not_delivered', (string) $target);
        $this->assertStringContainsString('salesman_ids', (string) $target);
        $this->assertStringContainsString('tab=daily-teams', (string) $target);
        $this->assertStringContainsString('team_date=2026-06-07', (string) $target);
    }

    public function test_assign_invoice_team_redirect_preserves_restricted_user_filters_from_post_body(): void
    {
        ReportAuthSession::login(10, 'deliveries-limited', false, ['deliveries'], new DeliveriesReportAccess(
            canFilterDate: true,
            canFilterCity: false,
            canFilterStorage: false,
            canFilterSalesman: true,
            canFilterStatus: true,
            canEditStatus: false,
        ));

        $teams = Mockery::mock(DeliveriesTeamSqliteService::class);
        $teams->shouldReceive('assignInvoiceTeam')
            ->once()
            ->with('INV-100', '2026-04-10', 7)
            ->andReturnNull();

        $this->app->instance(DeliveriesTeamSqliteService::class, $teams);

        $response = $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/reports/deliveries/assign-team', [
                'invoice_id' => 'INV-100',
                'document_date' => '2026-04-10',
                'team_id' => 7,
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-20',
                'delivery_status' => 'delivered',
                'salesman_ids' => [8],
            ]);

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertNotNull($target);
        $this->assertStringContainsString('date_from=2026-04-01', (string) $target);
        $this->assertStringContainsString('date_to=2026-04-20', (string) $target);
        $this->assertStringContainsString('delivery_status=delivered', (string) $target);
        $this->assertStringContainsString('salesman_ids', (string) $target);
        $this->assertStringContainsString('tab=report', (string) $target);
    }
}
