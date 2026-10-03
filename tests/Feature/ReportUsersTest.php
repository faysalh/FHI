<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ReportsUsersSqliteService;
use App\Support\ReportAuthSession;
use App\Support\ReportNavigation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ReportUsersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.reports_users_sqlite.database', ':memory:');
        DB::purge('reports_users_sqlite');
        Config::set('reporting.bootstrap_admin.username', 'bootstrap');
        Config::set('reporting.bootstrap_admin.password', 'secret-bootstrap');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sqlite_service_creates_user_with_permissions(): void
    {
        $service = new ReportsUsersSqliteService;
        $service->ensureReady();

        $id = $service->createUser('viewer', 'password123', false, ['sales', 'storage']);

        $user = $service->findUserById($id);
        $this->assertNotNull($user);
        $this->assertSame('viewer', $user->username);
        $this->assertSame(['sales', 'storage'], $user->report_keys);
    }

    public function test_client_balance_permission_is_saved_and_enforced(): void
    {
        $this->app['env'] = 'local';

        $service = $this->app->make(ReportsUsersSqliteService::class);
        $service->ensureReady();
        $userId = $service->createUser('balance-user', 'password123', false, ['client-balance']);

        $user = $service->findUserById($userId);
        $this->assertNotNull($user);
        $this->assertContains('client-balance', $user->report_keys);
        $this->assertContains('client-balance', ReportNavigation::assignablePermissionKeys());

        $visits = \Mockery::mock(\App\Repositories\VisitsReportRepository::class);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);
        $balances = \Mockery::mock(\App\Repositories\ClientBalanceReportRepository::class);
        $balances->shouldReceive('getYearOptions')->andReturn([]);
        $balances->shouldReceive('getCurrencyOptions')->andReturn([]);
        $balances->shouldReceive('resolveCurrentYearId')->andReturn(null);
        $this->app->instance(\App\Repositories\VisitsReportRepository::class, $visits);
        $this->app->instance(\App\Repositories\ClientBalanceReportRepository::class, $balances);

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => $userId,
            'reports_username' => 'balance-user',
            'reports_is_super_admin' => false,
            // Stale session on purpose: middleware should refresh from SQLite.
            'reports_allowed_keys' => ['sales'],
        ])->get('/reports/client-balance')
            ->assertOk()
            ->assertSee('Client balance', false);

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => $userId,
            'reports_username' => 'balance-user',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['client-balance'],
        ])->get('/reports/sales')
            ->assertForbidden();
    }

    public function test_permission_matrix_covers_all_mapped_report_routes(): void
    {
        $assignable = ReportNavigation::assignablePermissionKeys();
        $this->assertContains('client-balance', $assignable);
        $this->assertContains('rankings', $assignable);
        $this->assertContains('manufacturing', $assignable);
        $this->assertContains('face-id-employees', $assignable);

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! is_string($name) || ! str_starts_with($name, 'reports.')) {
                continue;
            }
            if (str_contains($name, 'no-access')) {
                continue;
            }

            $key = ReportNavigation::activeKey($name);
            if ($key === '' || in_array($key, ['guide', 'users', 'sqlite-backups', 'database-sync'], true)) {
                continue;
            }

            $this->assertContains(
                $key,
                $assignable,
                'Route '.$name.' maps to key '.$key.' which is missing from the Users permission matrix.'
            );
        }
    }

    public function test_sqlite_service_stores_deliveries_access_for_user(): void
    {
        $service = new ReportsUsersSqliteService;
        $service->ensureReady();

        $access = new \App\Support\DeliveriesReportAccess(
            canFilterDate: true,
            canFilterCity: false,
            canFilterStorage: false,
            canFilterSalesman: true,
            canFilterStatus: false,
            canEditStatus: false,
            defaultStorage: 'Warehouse A',
        );

        $id = $service->createUser('deliveries-viewer', 'password123', false, ['deliveries'], $access);
        $stored = $service->deliveriesAccessForUserId($id);

        $this->assertFalse($stored->canFilterCity);
        $this->assertFalse($stored->canFilterStorage);
        $this->assertFalse($stored->canEditStatus);
        $this->assertSame('Warehouse A', $stored->defaultStorage);
    }

    public function test_sqlite_service_stores_storage_access_for_user(): void
    {
        $service = new ReportsUsersSqliteService;
        $service->ensureReady();

        $access = new \App\Support\StorageReportAccess(
            canFilterStorage: false,
            allowedStorages: ['Warehouse A', 'Warehouse B'],
        );

        $id = $service->createUser('storage-viewer', 'password123', false, ['storage'], null, $access);
        $stored = $service->storageAccessForUserId($id);

        $this->assertFalse($stored->canFilterStorage);
        $this->assertSame(['Warehouse A', 'Warehouse B'], $stored->allowedStorages);
    }

    public function test_navigation_filters_reports_for_limited_user(): void
    {
        $sections = ReportNavigation::sectionsForUser(['sales'], false);
        $keys = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        $this->assertContains('sales', $keys);
        $this->assertNotContains('storage', $keys);
        $this->assertNotContains('users', $keys);
    }

    public function test_cities_permission_does_not_include_governorates_in_navigation(): void
    {
        $sections = ReportNavigation::sectionsForUser(['cities'], false);
        $keys = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        $this->assertContains('cities', $keys);
        $this->assertNotContains('governorates', $keys);
    }

    public function test_login_redirects_to_no_access_when_user_has_no_report_permissions(): void
    {
        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'viewer',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => [],
        ])->get('/login');

        $response->assertRedirect(route('reports.no-access'));
        $this->assertSame('reports.no-access', ReportAuthSession::defaultLandingRouteName());
    }

    public function test_users_page_renders_for_super_admin_session(): void
    {
        $service = new ReportsUsersSqliteService;
        $service->ensureReady();
        $adminId = $service->createUser('admin2', 'password123', true, []);

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => $adminId,
            'reports_username' => 'admin2',
            'reports_is_super_admin' => true,
            'reports_allowed_keys' => [],
        ])->get('/reports/users');

        $response->assertOk();
        $response->assertSee('Add user');
        $response->assertSee('Users');
    }
}
