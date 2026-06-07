<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\DamagesCatalogRepository;
use App\Repositories\StorageItemsReportRepository;
use App\Services\DamagesSqliteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DamagesReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $path = database_path('damages-test-'.uniqid('', true).'.sqlite');
        if (File::exists($path)) {
            File::delete($path);
        }
        config(['database.connections.damages_sqlite.database' => $path]);
        DB::purge('damages_sqlite');
    }

    public function test_damages_index_renders(): void
    {
        $response = $this->get(route('reports.damages.index'));

        $response->assertOk();
        $response->assertSee('Damaged goods', false);
        $response->assertSee('Packaging', false);
    }

    public function test_damage_entry_can_be_deleted(): void
    {
        $svc = app(DamagesSqliteService::class);
        $svc->insertEntry(
            '2026-01-15',
            'item-1',
            'Test item',
            'client-1',
            'Test client',
            null,
            null,
            2,
            10,
            100.0,
            20.0,
            null
        );
        $id = (int) DB::connection('damages_sqlite')->table('damage_entries')->max('id');

        $response = $this->from(route('reports.damages.index'))->post(route('reports.damages.entries.delete'), [
            'id' => $id,
            'tab' => 'damages',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertSame(
            0,
            (int) DB::connection('damages_sqlite')->table('damage_entries')->where('id', $id)->count()
        );
    }

    public function test_packaging_can_be_saved(): void
    {
        $response = $this->post(route('reports.damages.packaging.store'), [
            'main_item_id' => '99',
            'item_name' => 'Test item',
            'pieces_per_main_unit' => 10,
            'tab' => 'packaging',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $svc = app(DamagesSqliteService::class);
        $row = $svc->getPackagingForMainItem('99');
        $this->assertNotNull($row);
        $this->assertSame(10, (int) ($row->pieces_per_main_unit ?? 0));
    }

    public function test_api_items_returns_json_without_sqlsrv(): void
    {
        $this->mock(StorageItemsReportRepository::class, function ($mock): void {
            $mock->shouldReceive('searchStoreItemsForDamages')->andReturn([]);
        });

        $response = $this->get(route('reports.damages.api.items', ['q' => 'ab', 'as_of' => '2026-01-15']));

        $response->assertOk();
        $response->assertJson(['ok' => true, 'rows' => []]);
    }

    public function test_api_clients_returns_json_without_sqlsrv(): void
    {
        $this->mock(DamagesCatalogRepository::class, function ($mock): void {
            $mock->shouldReceive('searchClients')->andReturn([]);
        });

        $response = $this->get(route('reports.damages.api.clients', ['q' => 'ab']));

        $response->assertOk();
        $response->assertJson(['ok' => true, 'rows' => []]);
    }
}
