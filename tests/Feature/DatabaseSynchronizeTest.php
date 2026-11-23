<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DatabaseSynchronizeService;
use App\Services\ReportsUsersSqliteService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DatabaseSynchronizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.reports_users_sqlite.database', ':memory:');
        Config::set('reporting.bootstrap_admin.username', 'bootstrap');
        Config::set('reporting.bootstrap_admin.password', 'secret-bootstrap');
    }

    public function test_database_sync_page_renders_for_super_admin(): void
    {
        $service = new ReportsUsersSqliteService;
        $service->ensureReady();
        $adminId = $service->createUser('admin-sync', 'password123', true, []);

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => $adminId,
            'reports_username' => 'admin-sync',
            'reports_is_super_admin' => true,
            'reports_allowed_keys' => [],
        ])->get('/reports/database-sync');

        $response->assertOk();
        $response->assertSee('PDA synchronize');
        $response->assertSee('dbo.SP_Pda_Sync');
        $response->assertSee('Run PDA sync');
        $response->assertSee('Automatic PDA sync');
        $response->assertSee('Save auto sync');
    }

    public function test_auto_sync_settings_save_for_super_admin(): void
    {
        $service = new ReportsUsersSqliteService;
        $service->ensureReady();
        $adminId = $service->createUser('admin-sync', 'password123', true, []);

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => $adminId,
            'reports_username' => 'admin-sync',
            'reports_is_super_admin' => true,
            'reports_allowed_keys' => [],
        ])->post('/reports/database-sync/auto-settings', [
            'enabled' => '1',
            'interval_seconds' => 45,
            'agent_id' => 'all',
        ]);

        $response->assertRedirect(route('reports.database-sync.index'));
        $response->assertSessionHas('status');
    }

    public function test_auto_sync_settings_rejects_short_interval(): void
    {
        $service = new ReportsUsersSqliteService;
        $service->ensureReady();
        $adminId = $service->createUser('admin-sync', 'password123', true, []);

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => $adminId,
            'reports_username' => 'admin-sync',
            'reports_is_super_admin' => true,
            'reports_allowed_keys' => [],
        ])->post('/reports/database-sync/auto-settings', [
            'enabled' => '1',
            'interval_seconds' => 5,
            'agent_id' => 'all',
        ]);

        $response->assertSessionHasErrors('interval_seconds');

    public function test_database_sync_page_forbidden_for_non_admin(): void
    {
        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'viewer',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['sales'],
        ])->get('/reports/database-sync');

        $response->assertForbidden();
    }

    public function test_database_sync_run_invokes_service_for_super_admin(): void
    {
        $this->mock(DatabaseSynchronizeService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with('admin-sync', 'all');
        });

        $service = new ReportsUsersSqliteService;
        $service->ensureReady();
        $adminId = $service->createUser('admin-sync', 'password123', true, []);

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => $adminId,
            'reports_username' => 'admin-sync',
            'reports_is_super_admin' => true,
            'reports_allowed_keys' => [],
        ])->post('/reports/database-sync/run', [
            'agent_id' => 'all',
        ]);

        $response->assertRedirect(route('reports.database-sync.index'));
        $response->assertSessionHas('status');
    }
}
