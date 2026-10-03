<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ReportNavigation;
use Tests\TestCase;

class ClientBalanceReportTest extends TestCase
{
    public function test_page_requires_permission(): void
    {
        $this->app['env'] = 'local';
        \Illuminate\Support\Facades\Config::set('database.connections.reports_users_sqlite.database', ':memory:');
        \Illuminate\Support\Facades\DB::purge('reports_users_sqlite');

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 2,
            'reports_username' => 'sales-only',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['sales'],
        ])->get('/reports/client-balance')
            ->assertForbidden();
    }

    public function test_page_renders_and_asks_for_salesman(): void
    {
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'finance',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['client-balance'],
        ])->get('/reports/client-balance')
            ->assertOk()
            ->assertSee('Client balance', false)
            ->assertSee('Choose a salesman', false)
            ->assertSee('Salesman', false);
    }

    public function test_permission_key_in_nav_matrix(): void
    {
        $keys = array_column(ReportNavigation::permissionMatrix(), 'key');
        $this->assertContains('client-balance', $keys);
        $this->assertContains('accounting', $keys);
    }

    public function test_export_requires_salesman(): void
    {
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'finance',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['client-balance'],
        ])->get('/reports/client-balance/export/pdf')
            ->assertRedirect();
    }
}
