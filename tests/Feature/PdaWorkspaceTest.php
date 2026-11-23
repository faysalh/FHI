<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\PdaVoucherRequest;
use App\Services\ClientSuspensionSqliteService;
use App\Services\PdaLocationSqliteService;
use App\Services\PdaWorkspaceService;
use App\Services\ReportsUsersSqliteService;
use App\Support\ReportAuthSession;
use App\Support\ReportNavigation;
use ReflectionMethod;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public function test_a_voucher_without_a_location_fix_is_allowed(): void
    {
        $this->assertVoucherLocationError(null, null, false);
        $this->assertVoucherLocationError('', '', false);
        $this->assertVoucherLocationError(0, 0, true);
        $this->assertVoucherLocationError(36.2, null, true);

        $script = (string) file_get_contents(resource_path('views/reports/pda/partials/location-script.blade.php'));
        $this->assertStringContainsString('function saveWithoutLocation()', $script);
        $this->assertStringContainsString('Saving without a location.', $script);
        $this->assertStringNotContainsString('Turn on location for this phone, then save again.', $script);
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

    public function test_pda_only_user_lands_on_pda_without_the_report_menu(): void
    {
        $users = new ReportsUsersSqliteService;
        $users->ensureReady();
        $users->createUser('pda-only', 'password123', false, ['pda', 'pda-zero-stock']);

        $login = $this->withSession([
            'reports_admin_intended_url' => 'http://127.0.0.1:8024/reports/sales',
        ])->post('/login', [
            'username' => 'pda-only',
            'password' => 'password123',
        ]);

        $login->assertRedirect(route('reports.pda.index'));
        $this->assertTrue(ReportAuthSession::isPdaFocused());
        $this->assertSame('reports.pda.index', ReportAuthSession::defaultLandingRouteName());
        $this->assertTrue(ReportAuthSession::mustStayOnPda('reports.sales.index'));
        $this->assertFalse(ReportAuthSession::mustStayOnPda('reports.pda.vouchers.create'));
        $this->assertFalse(ReportAuthSession::mustStayOnPda('reports.guide.index'));

        $page = $this->get('/reports/pda');
        $page->assertOk();
        $page->assertDontSee('class="report-nav-toggle btn-icon"', false);
        $page->assertDontSee('Open report menu', false);
        $page->assertDontSee('id="report-nav-menu"', false);
        $page->assertSee('Customers', false);
        $page->assertSee('Unposted', false);
        $page->assertSee('Today', false);
        $page->assertSee(route('reports.pda.today'), false);

        $this->get('/')->assertRedirect(route('reports.pda.index'));
    }

    public function test_pda_only_login_keeps_a_pda_page_that_was_requested(): void
    {
        $users = new ReportsUsersSqliteService;
        $users->ensureReady();
        $users->createUser('pda-return', 'password123', false, ['pda']);

        $target = 'http://127.0.0.1:8024/reports/pda/unposted';
        $this->withSession([
            'reports_admin_intended_url' => $target,
        ])->post('/login', [
            'username' => 'pda-return',
            'password' => 'password123',
        ])->assertRedirect($target);
    }

    public function test_user_with_pda_and_another_report_keeps_the_report_menu(): void
    {
        $page = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 9,
            'reports_username' => 'mixed',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['pda', 'invoices'],
        ])->get('/reports/pda');

        $page->assertOk();
        $page->assertSee('class="report-nav-toggle btn-icon"', false);
        $page->assertSee('Open report menu', false);
        $page->assertSee('id="report-nav-menu"', false);
        $this->assertFalse(ReportAuthSession::isPdaFocused());
        $this->get('/')->assertOk();
    }

    public function test_a_sale_cannot_exceed_the_available_quantity(): void
    {
        $service = new PdaWorkspaceService(
            new ReportsUsersSqliteService,
            new PdaLocationSqliteService,
            new ClientSuspensionSqliteService
        );
        $guard = new ReflectionMethod(PdaWorkspaceService::class, 'takeSaleStock');

        $this->assertSame(5.0, $guard->invoke($service, 'sale', 'Chicken', 5.0, 0.0, 5.0, 1.0));
        $this->assertSame(5.0, $guard->invoke($service, 'sale', 'Chicken', 5.0, 0.0, 0.5, 10.0));
        $this->assertSame(0.0, $guard->invoke($service, 'order', 'Chicken', 5.0, 0.0, 10.0, 1.0));
        $this->assertSame(0.0, $guard->invoke($service, 'sale', 'Chicken', 0.0, 0.0, 4.0, 1.0));

        try {
            $guard->invoke($service, 'sale', 'Chicken', 5.0, 4.0, 2.0, 1.0);
            $this->fail('A sale past the available quantity was accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('Chicken only has 5 available.', $e->getMessage());
        }
    }

    public function test_refresh_customers_drops_the_cached_list(): void
    {
        $users = new ReportsUsersSqliteService;
        $users->ensureReady();
        $id = $users->createUser('pda-refresh', 'password123', false, ['pda']);
        $salesmanId = '11111111-1111-1111-1111-111111111111';
        $users->savePdaSalesmanLink(
            $id,
            $salesmanId,
            '22222222-2222-2222-2222-222222222222',
            'Ali'
        );
        Cache::put('pda.customers.v3.'.$salesmanId, [['id' => 'old']], 180);

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => $id,
            'reports_username' => 'pda-refresh',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['pda'],
        ])->post(route('reports.pda.customers.refresh'))
            ->assertRedirect(route('reports.pda.index'))
            ->assertSessionHas('status', 'Customer list refreshed.');

        $this->assertFalse(Cache::has('pda.customers.v3.'.$salesmanId));
    }

    public function test_sales_steps_offer_refresh_customers_instead_of_the_guide(): void
    {
        $salesman = ['user_id' => '11111111-1111-1111-1111-111111111111'];

        $customers = $this->renderHowToLink('reports.pda.index', $salesman);
        $this->assertStringContainsString('Refresh customers', $customers);
        $this->assertStringContainsString(route('reports.pda.customers.refresh'), $customers);
        $this->assertStringNotContainsString('How to use this page', $customers);

        foreach (['reports.pda.customers.show', 'reports.pda.visits.show', 'reports.pda.vouchers.create'] as $routeName) {
            $html = $this->renderHowToLink($routeName, $salesman);
            $this->assertStringContainsString('Refresh customers', $html);
            $this->assertStringNotContainsString('How to use this page', $html);
        }

        $items = $this->renderHowToLink('reports.pda.items', $salesman);
        $this->assertStringContainsString('How to use this page', $items);
        $this->assertStringNotContainsString('Refresh customers', $items);

        $unlinked = $this->renderHowToLink('reports.pda.index', []);
        $this->assertStringContainsString('How to use this page', $unlinked);
        $this->assertStringNotContainsString('Refresh customers', $unlinked);
    }

    private function assertVoucherLocationError(mixed $lat, mixed $lng, bool $expectsLocationError): void
    {
        $request = PdaVoucherRequest::create('/reports/pda/visits/x/voucher', 'POST', [
            'kind' => 'sale',
            'lat' => $lat,
            'lng' => $lng,
            'lines' => [[
                'item_id' => '11111111-1111-1111-1111-111111111111',
                'unit_row_id' => '22222222-2222-2222-2222-222222222222',
                'qty' => 1,
                'price' => 1000,
            ]],
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        $errors = [];
        try {
            $request->validateResolved();
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        if ($expectsLocationError) {
            $this->assertArrayHasKey('lat', $errors);
        } else {
            $this->assertArrayNotHasKey('lat', $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $salesman
     */
    private function renderHowToLink(string $routeName, array $salesman): string
    {
        $request = Request::create('/reports/pda', 'GET');
        $route = new Route('GET', '/reports/pda', static fn () => null);
        $route->name($routeName);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        return view('reports.partials.report-how-to-link', [
            'salesman' => $salesman,
        ])->render();
    }
}
