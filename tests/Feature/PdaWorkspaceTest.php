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
use Mockery;
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

    public function test_locations_page_filters_latest_points_and_voucher_customers(): void
    {
        $pda = Mockery::mock(PdaWorkspaceService::class, [
            new ReportsUsersSqliteService,
            new PdaLocationSqliteService,
            new ClientSuspensionSqliteService,
        ])->makePartial();
        $pda->shouldReceive('listSalesmen')->andReturn([
            [
                'user_id' => '11111111-1111-1111-1111-111111111111',
                'account_id' => '22222222-2222-2222-2222-222222222222',
                'name' => 'Ali',
                'code' => '1',
            ],
        ]);
        $pda->shouldReceive('companyName')->andReturn('');
        $pda->shouldReceive('lastLocations')->andReturn([
            (object) ['fld_account_name' => 'Ali', 'fld_date' => '2026-10-05 08:00:00', 'Lat' => '36.100', 'Long' => '44.100', 'fld_vehicle_name' => 'Van'],
            (object) ['fld_account_name' => 'Ali', 'fld_date' => '2026-09-01 08:00:00', 'Lat' => '1.000', 'Long' => '2.000', 'fld_vehicle_name' => ''],
            (object) ['fld_account_name' => 'Omar', 'fld_date' => '2026-10-05 08:00:00', 'Lat' => '3.000', 'Long' => '4.000', 'fld_vehicle_name' => ''],
        ]);
        $pda->shouldReceive('voucherLocations')->once()->with([
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-10',
            'salesman_user_id' => '11111111-1111-1111-1111-111111111111',
            'client_name' => 'Shop',
        ])->andReturn([
            (object) [
                'customer_name' => 'Shop A',
                'voucher_type' => 'sale',
                'captured_at' => '2026-10-05 09:00:00',
                'salesman_name' => 'Ali',
                'latitude' => 36.2,
                'longitude' => 44.0,
            ],
        ]);
        $this->app->instance(PdaWorkspaceService::class, $pda);

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'admin',
            'reports_is_super_admin' => true,
            'reports_allowed_keys' => ['pda-locations'],
        ])->get('/reports/pda/locations?date_from=2026-10-10&date_to=2026-10-01&salesman_user_id=11111111-1111-1111-1111-111111111111&client_name=Shop');

        $response->assertOk();
        $response->assertSee('name="date_from"', false);
        $response->assertSee('name="salesman_user_id"', false);
        $response->assertSee('name="client_name"', false);
        $response->assertSee('value="Shop"', false);
        $response->assertSee('36.100');
        $response->assertDontSee('2026-09-01');
        $response->assertDontSee('Omar');
        $response->assertSee('Shop A');
    }

    public function test_location_filters_drop_invalid_dates_and_salesman_ids(): void
    {
        $service = new PdaWorkspaceService(new ReportsUsersSqliteService, new PdaLocationSqliteService, new ClientSuspensionSqliteService);

        $this->assertSame([
            'date_from' => '',
            'date_to' => '2026-10-29',
            'salesman_user_id' => '',
            'client_name' => 'بازار',
        ], $service->normalizeLocationFilters([
            'date_from' => '2026-13-01',
            'date_to' => '2026-10-29',
            'salesman_user_id' => 'not-a-guid',
            'client_name' => ' بازار ',
        ]));

        $points = $service->filterLastLocations([
            (object) ['fld_account_name' => 'Ali', 'fld_date' => '2026-10-29 12:00:00'],
        ], [
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'salesman_name' => 'Ali',
        ]);
        $this->assertCount(1, $points);
        $this->assertSame([], $service->filterLastLocations($points, ['salesman_name' => 'Omar']));
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
