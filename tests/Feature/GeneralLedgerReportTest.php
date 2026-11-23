<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ReportNavigation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeneralLedgerReportTest extends TestCase
{
    public function test_page_requires_permission(): void
    {
        $this->app['env'] = 'local';
        Config::set('database.connections.reports_users_sqlite.database', ':memory:');
        DB::purge('reports_users_sqlite');

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 2,
            'reports_username' => 'sales-only',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['sales'],
        ])->get('/reports/general-ledger')
            ->assertForbidden();
    }

    public function test_page_renders_with_filters(): void
    {
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'finance',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['general-ledger'],
        ])->get('/reports/general-ledger')
            ->assertOk()
            ->assertSee('General ledger', false)
            ->assertSee('Date from', false)
            ->assertSee('Account', false)
            ->assertSee('Salesman', false);
    }

    public function test_permission_key_in_nav_matrix(): void
    {
        $keys = array_column(ReportNavigation::permissionMatrix(), 'key');
        $this->assertContains('general-ledger', $keys);
        $this->assertSame('general-ledger', ReportNavigation::activeKey('reports.general-ledger.index'));
        $this->assertSame('general-ledger', ReportNavigation::activeKey('reports.general-ledger.export.pdf'));
    }

    public function test_export_requires_account_or_salesman(): void
    {
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'finance',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['general-ledger'],
        ])->get('/reports/general-ledger/export/pdf', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ])->assertRedirect();
    }
}
