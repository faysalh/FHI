<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AccountingSqliteService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingCashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.accounting_sqlite.database', ':memory:');
        DB::purge('accounting_sqlite');
        $this->withAccountingSession();
    }

    public function test_cash_sheet_and_spend_rows_update_remaining_balance(): void
    {
        $date = '2026-06-07';

        $this->post('/reports/accounting/cash/sheet', [
            'sheet_date' => $date,
            'opening_amount' => 100000,
            'tab' => 'cash',
        ])->assertRedirect();

        $this->post('/reports/accounting/cash/rows', [
            'sheet_date' => $date,
            'amount' => 25000,
            'paid_to' => 'Supplier A',
            'note' => 'Materials',
            'tab' => 'cash',
        ])->assertRedirect();

        $svc = app(AccountingSqliteService::class);
        $bundle = $svc->cashSheetBundle($date);

        $this->assertSame(100000.0, (float) ($bundle['sheet']->opening_amount ?? 0));
        $this->assertSame(25000.0, $bundle['spent']);
        $this->assertSame(75000.0, $bundle['remaining']);
        $this->assertCount(1, $bundle['rows']);
    }

    public function test_money_tracker_tab_renders(): void
    {
        $response = $this->get('/reports/accounting?tab=cash');

        $response->assertOk();
        $response->assertSee('Money tracker', false);
        $response->assertSee('Opening amount', false);
    }

    private function withAccountingSession(): void
    {
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'accountant',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['accounting'],
        ]);
    }
}
