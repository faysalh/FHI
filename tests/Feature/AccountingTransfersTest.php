<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AccountingSqliteService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingTransfersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.accounting_sqlite.database', ':memory:');
        DB::purge('accounting_sqlite');
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'accountant',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['accounting'],
        ]);
    }

    public function test_usd_transfer_requires_rate(): void
    {
        $response = $this->post('/reports/accounting/transfers/rows', [
            'transfer_date' => '2026-06-07',
            'amount' => 100,
            'currency' => 'USD',
            'person_name' => 'Bank client',
            'note' => 'Wire',
            'tab' => 'transfers',
        ]);

        $response->assertSessionHasErrors('usd_rate');
    }

    public function test_usd_transfer_stores_iqd_equivalent(): void
    {
        $this->post('/reports/accounting/transfers/rows', [
            'transfer_date' => '2026-06-07',
            'amount' => 100,
            'currency' => 'USD',
            'usd_rate' => 1500,
            'person_name' => 'Bank client',
            'note' => 'Wire',
            'tab' => 'transfers',
        ])->assertRedirect();

        $rows = app(AccountingSqliteService::class)->listTransferRowsForDate('2026-06-07');
        $this->assertCount(1, $rows);
        $this->assertSame(150000.0, app(AccountingSqliteService::class)->transferIqdEquivalent($rows[0]));
    }
}
