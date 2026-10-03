<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        Config::set('database.connections.deliveries_sqlite.database', ':memory:');
        Config::set('database.connections.reports_users_sqlite.database', ':memory:');
        DB::purge('deliveries_sqlite');
        DB::purge('reports_users_sqlite');
    }

    public function test_accounting_requires_permission(): void
    {
        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 2,
            'reports_username' => 'sales-only',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['sales'],
        ])->get('/reports/accounting');

        $response->assertForbidden();
    }

    public function test_accounting_allowed_with_permission_shows_receipts_only(): void
    {
        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 3,
            'reports_username' => 'accountant',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['accounting'],
        ])->get('/reports/accounting');

        $response->assertOk();
        $response->assertSee('Accounting — Receipts', false);
        $response->assertSee('Add receipt booklets', false);
        $response->assertDontSee('Money tracker', false);
        $response->assertDontSee('>Transfers<', false);
    }

    public function test_non_receipts_tab_redirects_to_receipts(): void
    {
        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 3,
            'reports_username' => 'accountant',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['accounting'],
        ])->get('/reports/accounting?tab=cash');

        $response->assertRedirect(route('reports.accounting.index', ['tab' => 'receipts']));
    }

    public function test_accounting_key_appears_in_permission_matrix(): void
    {
        $keys = array_column(\App\Support\ReportNavigation::permissionMatrix(), 'key');
        $this->assertContains('accounting', $keys);
    }
}
