<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ManufacturingSqliteService;
use App\Support\ReportNavigation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManufacturingStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.manufacturing_sqlite.database', ':memory:');
        DB::purge('manufacturing_sqlite');
        $this->withManufacturingSession();
    }

    public function test_page_renders_stock_tab(): void
    {
        $response = $this->get('/reports/manufacturing?tab=stock');

        $response->assertOk();
        $response->assertSee('Manufacturing Storage', false);
        $response->assertSee('Stock', false);
    }

    public function test_item_purchase_export_updates_stock_balance(): void
    {
        $svc = app(ManufacturingSqliteService::class);
        $itemId = $svc->addItem('Flour', 'kg');

        $this->post('/reports/manufacturing/purchases', [
            'item_id' => $itemId,
            'purchase_date' => '2026-06-01',
            'quantity' => 100,
            'cost_amount' => 50000,
            'currency' => 'IQD',
            'supplier_name' => 'Supplier A',
            'tab' => 'purchases',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ])->assertRedirect();

        $this->post('/reports/manufacturing/exports', [
            'item_id' => $itemId,
            'export_date' => '2026-06-02',
            'quantity' => 30,
            'tab' => 'exports',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ])->assertRedirect();

        $balances = $svc->stockBalances();
        $this->assertCount(1, $balances);
        $this->assertSame(100.0, (float) $balances[0]->purchased_qty);
        $this->assertSame(30.0, (float) $balances[0]->exported_qty);
        $this->assertSame(70.0, (float) $balances[0]->balance);
    }

    public function test_export_cannot_exceed_stock(): void
    {
        $svc = app(ManufacturingSqliteService::class);
        $itemId = $svc->addItem('Sugar', 'kg');
        $svc->addPurchase($itemId, '2026-06-01', 10, 1000, 'IQD', 'S1', '');

        $this->post('/reports/manufacturing/exports', [
            'item_id' => $itemId,
            'export_date' => '2026-06-02',
            'quantity' => 15,
            'tab' => 'exports',
        ])->assertRedirect();

        $this->assertSame(10.0, $svc->itemBalance($itemId));
        $this->assertCount(0, $svc->listExports('2026-06-01', '2026-06-30'));
    }

    public function test_usd_purchase_stores_without_rate_and_exports_csv(): void
    {
        $svc = app(ManufacturingSqliteService::class);
        $itemId = $svc->addItem('Oil', 'liter');

        $this->post('/reports/manufacturing/purchases', [
            'item_id' => $itemId,
            'purchase_date' => '2026-06-05',
            'quantity' => 5,
            'cost_amount' => 20,
            'currency' => 'USD',
            'supplier_name' => 'Import Co',
            'note' => 'rate ~1500',
            'tab' => 'purchases',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ])->assertRedirect();

        $rows = $svc->listPurchases('2026-06-01', '2026-06-30');
        $this->assertCount(1, $rows);
        $this->assertSame('USD', strtoupper((string) $rows[0]->currency));
        $this->assertSame(20.0, (float) $rows[0]->cost_amount);

        $csv = $this->get('/reports/manufacturing/export/purchases/csv?date_from=2026-06-01&date_to=2026-06-30');
        $csv->assertOk();
    }

    public function test_bulk_paste_imports_items_with_units(): void
    {
        $response = $this->post('/reports/manufacturing/items/bulk', [
            'bulk_lines' => "Flour, kg\nSugar, SUG-01, kg\nCooking oil, liter",
            'tab' => 'items',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $items = app(ManufacturingSqliteService::class)->listItems();
        $this->assertCount(3, $items);
        $byName = [];
        foreach ($items as $item) {
            $byName[(string) $item->name] = $item;
        }
        $this->assertSame('kg', (string) $byName['Flour']->unit);
        $this->assertSame('SUG-01', (string) $byName['Sugar']->code);
        $this->assertSame('liter', (string) $byName['Cooking oil']->unit);
    }

    public function test_bulk_import_updates_existing_items_when_enabled(): void
    {
        $svc = app(ManufacturingSqliteService::class);
        $svc->addItem('Flour', 'kg', 'OLD');

        $response = $this->post('/reports/manufacturing/items/bulk', [
            'bulk_lines' => "Flour, carton, NEW-CODE",
            'update_existing' => '1',
            'tab' => 'items',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $items = $svc->listItems();
        $this->assertCount(1, $items);
        $this->assertSame('carton', (string) $items[0]->unit);
        $this->assertSame('NEW-CODE', (string) $items[0]->code);
    }

    public function test_permission_required(): void
    {
        $this->app['env'] = 'local';

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 2,
            'reports_username' => 'sales-only',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['sales'],
        ])->get('/reports/manufacturing');

        $response->assertForbidden();
    }

    public function test_delete_purchase_blocked_when_used_by_exports(): void
    {
        $svc = app(ManufacturingSqliteService::class);
        $itemId = $svc->addItem('Salt', 'kg');
        $purchaseId = $svc->addPurchase($itemId, '2026-06-01', 50, 1000, 'IQD', 'S1', '');
        $svc->addExport($itemId, '2026-06-02', 20, '');

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'supervisor',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['manufacturing', 'manufacturing-delete'],
        ]);

        $response = $this->delete('/reports/manufacturing/purchases/'.$purchaseId, [
            'tab' => 'purchases',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('exports to manufacturing', (string) session('error'));
        $this->assertNotNull($svc->findPurchase($purchaseId));
    }

    public function test_delete_requires_separate_permission(): void
    {
        $this->app['env'] = 'local';

        $svc = app(ManufacturingSqliteService::class);
        $itemId = $svc->addItem('Yeast', 'kg');
        $purchaseId = $svc->addPurchase($itemId, '2026-06-01', 10, 500, 'IQD', 'S1', '');

        $response = $this->delete('/reports/manufacturing/purchases/'.$purchaseId, [
            'tab' => 'purchases',
        ]);

        $response->assertForbidden();
        $this->assertNotNull($svc->findPurchase($purchaseId));
    }

    public function test_delete_permission_not_shown_in_nav(): void
    {
        $sections = ReportNavigation::sectionsForUser(
            ['manufacturing', 'manufacturing-delete'],
            false
        );
        $keys = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        $this->assertContains('manufacturing', $keys);
        $this->assertNotContains('manufacturing-delete', $keys);
        $this->assertContains(
            'manufacturing-delete',
            array_column(ReportNavigation::permissionMatrix(), 'key')
        );
    }

    private function withManufacturingSession(): void
    {
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'mfg',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['manufacturing'],
        ]);
    }
}
