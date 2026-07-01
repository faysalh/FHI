<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AccountingPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
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

    public function test_accounting_allowed_with_permission(): void
    {
        Config::set('database.connections.accounting_sqlite.database', ':memory:');

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 3,
            'reports_username' => 'accountant',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['accounting'],
        ])->get('/reports/accounting');

        $response->assertOk();
    }
}
