<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ClientSuspensionSqliteService;
use App\Services\PdaLocationSqliteService;
use App\Services\PdaWorkspaceService;
use App\Services\ReportsUsersSqliteService;
use App\Support\ReportNavigation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PdaWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.reports_users_sqlite.database', ':memory:');
        Config::set('database.connections.pda_locations_sqlite.database', ':memory:');
        Config::set('database.connections.client_suspensions_sqlite.database', ':memory:');
        DB::purge('reports_users_sqlite');
        DB::purge('pda_locations_sqlite');
        DB::purge('client_suspensions_sqlite');
    }

    public function test_navigation_includes_pda_for_a_user_with_access(): void
    {
        $sections = ReportNavigation::sectionsForUser(['pda'], false);
        $labels = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $labels[] = $item['label'];
            }
        }

        $this->assertContains('PDA', $labels);
        $this->assertContains('pda', ReportNavigation::assignablePermissionKeys());
        $this->assertContains('pda-locations', ReportNavigation::assignablePermissionKeys());
        $this->assertContains('pda-item-detail', ReportNavigation::assignablePermissionKeys());
        $this->assertContains('pda-zero-stock', ReportNavigation::assignablePermissionKeys());
        $this->assertSame('pda', ReportNavigation::activeKey('reports.pda.vouchers.store'));
        $this->assertSame('pda', ReportNavigation::activeKey('reports.pda.items'));
        $this->assertSame('pda-locations', ReportNavigation::activeKey('reports.pda.locations'));
        $this->assertSame('pda-item-detail', ReportNavigation::activeKey('reports.pda.items.show'));
    }

    public function test_salesman_link_is_stored_on_the_user(): void
    {
        $users = new ReportsUsersSqliteService;
        $users->ensureReady();
        $id = $users->createUser('pda-user', 'password123', false, ['pda']);
        $users->savePdaSalesmanLink(
            $id,
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            'Ali'
        );

        $user = $users->findUserById($id);
        $this->assertNotNull($user);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $user->pda_salesman_user_id);
        $this->assertSame('Ali', $user->pda_salesman_name);

        $link = (new PdaWorkspaceService($users, new PdaLocationSqliteService, new ClientSuspensionSqliteService))->linkedSalesman($id);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $link['account_id'] ?? null);
    }

    public function test_voucher_location_is_stored_in_sqlite(): void
    {
        $locations = new PdaLocationSqliteService;
        $locations->record(
            '33333333-3333-3333-3333-333333333333',
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            'Ali',
            '44444444-4444-4444-4444-444444444444',
            'Shop',
            'sale',
            36.19,
            44.01,
            12.5,
        );

        $rows = $locations->recent('11111111-1111-1111-1111-111111111111');
        $this->assertCount(1, $rows);
        $this->assertSame('Shop', $rows[0]->customer_name);
        $this->assertEquals(36.19, (float) $rows[0]->latitude);
        $this->assertSame([], $locations->recent('99999999-9999-9999-9999-999999999999'));
    }

    public function test_missing_location_is_rejected(): void
    {
        $service = new PdaWorkspaceService(new ReportsUsersSqliteService, new PdaLocationSqliteService, new ClientSuspensionSqliteService);
        $this->expectException(RuntimeException::class);
        $service->assertCoordinates(0.0, 0.0);
    }

    public function test_unlinked_login_sees_the_pda_message(): void
    {
        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 4242,
            'reports_username' => 'nobody',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['pda'],
        ])->get('/reports/pda');

        $response->assertOk();
        $response->assertSee('not linked to a salesman');
    }

    public function test_pda_user_cannot_open_every_salesman_locations(): void
    {
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 7,
            'reports_username' => 'seller',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['pda'],
        ])->get('/reports/pda/locations')->assertForbidden();
    }

    public function test_pda_user_cannot_open_item_price_and_store_stock(): void
    {
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 7,
            'reports_username' => 'seller',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['pda'],
        ])->get('/reports/pda/items/BD799886-F787-4CE7-B484-1EFB027DA26B')->assertForbidden();
    }
}
