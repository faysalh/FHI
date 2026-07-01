<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingReceiptsMoveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.deliveries_sqlite.database', ':memory:');
        DB::purge('deliveries_sqlite');
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'accountant',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['accounting'],
        ]);
    }

    public function test_deliveries_receipts_tab_redirects_to_accounting(): void
    {
        $response = $this->get('/reports/deliveries?tab=receipts');

        $response->assertRedirect(route('reports.accounting.index', ['tab' => 'receipts']));
    }

    public function test_receipts_tab_renders_under_accounting(): void
    {
        $response = $this->get('/reports/accounting?tab=receipts');

        $response->assertOk();
        $response->assertSee('Add receipt booklets', false);
    }
}
