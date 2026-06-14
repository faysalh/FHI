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
    }

    public function test_receipts_tab_renders(): void
    {
        $response = $this->get('/reports/deliveries?tab=receipts');

        $response->assertOk();
        $response->assertSee('Receipts', false);
        $response->assertSee('Add receipt booklets', false);
    }

    public function test_adding_range_creates_fifty_number_booklets(): void
    {
        $response = $this->post('/reports/deliveries/receipts/booklets?tab=receipts', [
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

        $assign = $this->post('/reports/deliveries/receipts/assign?tab=receipts', [
            'start_number' => 2051,
            'driver_name' => 'Zirak',
            'tab' => 'receipts',
        ]);
        $assign->assertRedirect();
        $this->assertCount(1, $svc->listAssignedActive());

        $booklet = $svc->listAssignedActive()[0];
        $return = $this->post('/reports/deliveries/receipts/return?tab=receipts', [
            'booklet_id' => (int) $booklet->id,
            'tab' => 'receipts',
        ]);
        $return->assertRedirect();

        $this->assertCount(0, $svc->listAssignedActive());
        $this->assertCount(1, $svc->listReturned());
        $this->assertNotNull($svc->listReturned()[0]->returned_at ?? null);
    }
}
