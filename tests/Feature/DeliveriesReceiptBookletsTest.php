<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DeliveriesReceiptBookletSqliteService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DeliveriesReceiptBookletsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $path = database_path('deliveries-receipts-test-'.uniqid('', true).'.sqlite');
        if (File::exists($path)) {
            File::delete($path);
        }
        Config::set('database.connections.deliveries_sqlite.database', $path);
        DB::purge('deliveries_sqlite');

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'accountant',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['accounting'],
        ]);
    }

    public function test_receipts_tab_renders(): void
    {
        $response = $this->get('/reports/accounting?tab=receipts');

        $response->assertOk();
        $response->assertSee('Receipts', false);
        $response->assertSee('Add receipt booklets', false);
    }

    public function test_adding_range_creates_fifty_number_booklets(): void
    {
        $response = $this->post('/reports/accounting/receipts/booklets?tab=receipts', [
            'first_number' => 2051,
            'last_number' => 2200,
            'tab' => 'receipts',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $svc = app(DeliveriesReceiptBookletSqliteService::class);
        $this->assertCount(3, $svc->listUnassigned());
    }

    public function test_assign_and_return_moves_booklet_between_lists(): void
    {
        $svc = app(DeliveriesReceiptBookletSqliteService::class);
        $svc->addBookletsFromRange(2051, 2100);

        $assign = $this->post('/reports/accounting/receipts/assign?tab=receipts', [
            'start_number' => 2051,
            'driver_name' => 'Zirak',
            'tab' => 'receipts',
        ]);
        $assign->assertRedirect();
        $this->assertCount(1, $svc->listAssignedActive());

        $booklet = $svc->listAssignedActive()[0];
        $return = $this->post('/reports/accounting/receipts/return?tab=receipts', [
            'booklet_id' => (int) $booklet->id,
            'tab' => 'receipts',
        ]);
        $return->assertRedirect();

        $this->assertCount(0, $svc->listAssignedActive());
        $this->assertCount(1, $svc->listReturned());
        $this->assertNotNull($svc->listReturned()[0]->returned_at ?? null);
    }

    public function test_edit_unassigned_booklet_numbers(): void
    {
        $svc = app(DeliveriesReceiptBookletSqliteService::class);
        $svc->addBookletsFromRange(2051, 2100);
        $booklet = $svc->listUnassigned()[0];

        $response = $this->put('/reports/accounting/receipts/booklets/'.(int) $booklet->id.'?tab=receipts', [
            'start_number' => 3001,
            'end_number' => 3050,
            'tab' => 'receipts',
        ]);

        $response->assertRedirect();
        $updated = $svc->listUnassigned()[0];
        $this->assertSame(3001, (int) $updated->start_number);
        $this->assertSame(3050, (int) $updated->end_number);
    }

    public function test_edit_assigned_driver_and_unassign(): void
    {
        $svc = app(DeliveriesReceiptBookletSqliteService::class);
        $svc->addBookletsFromRange(2051, 2100);
        $svc->assignByStartNumber(2051, 'Zirak');
        $booklet = $svc->listAssignedActive()[0];

        $this->put('/reports/accounting/receipts/booklets/'.(int) $booklet->id.'?tab=receipts', [
            'driver_name' => 'Ahmad',
            'tab' => 'receipts',
        ])->assertRedirect();

        $this->assertSame('Ahmad', $svc->listAssignedActive()[0]->assigned_driver);

        $this->put('/reports/accounting/receipts/booklets/'.(int) $booklet->id.'?tab=receipts', [
            'unassign' => 1,
            'tab' => 'receipts',
        ])->assertRedirect();

        $this->assertCount(1, $svc->listUnassigned());
        $this->assertCount(0, $svc->listAssignedActive());
    }

    public function test_reopen_returned_booklet(): void
    {
        $svc = app(DeliveriesReceiptBookletSqliteService::class);
        $svc->addBookletsFromRange(2051, 2100);
        $svc->assignByStartNumber(2051, 'Zirak');
        $booklet = $svc->listAssignedActive()[0];
        $svc->markReturned((int) $booklet->id);

        $this->put('/reports/accounting/receipts/booklets/'.(int) $booklet->id.'?tab=receipts', [
            'undo_return' => 1,
            'tab' => 'receipts',
        ])->assertRedirect();

        $this->assertCount(1, $svc->listAssignedActive());
        $this->assertCount(0, $svc->listReturned());
    }

    public function test_delete_booklet(): void
    {
        $svc = app(DeliveriesReceiptBookletSqliteService::class);
        $svc->addBookletsFromRange(2051, 2100);
        $booklet = $svc->listUnassigned()[0];

        $this->delete('/reports/accounting/receipts/booklets/'.(int) $booklet->id.'?tab=receipts', [
            'tab' => 'receipts',
        ])->assertRedirect();

        $this->assertCount(0, $svc->listUnassigned());
    }
}
