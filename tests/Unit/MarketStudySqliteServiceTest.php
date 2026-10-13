<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MarketStudySqliteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MarketStudySqliteServiceTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = storage_path('app/test-market-study-'.uniqid('', true).'.sqlite');
        config(['database.connections.market_study_sqlite.database' => $this->dbPath]);

        DB::purge('market_study_sqlite');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('market_study_sqlite');
        if ($this->dbPath !== '' && File::exists($this->dbPath)) {
            File::delete($this->dbPath);
        }

        parent::tearDown();
    }

    public function test_create_list_update_and_delete_are_shared(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00'));

        $service = new MarketStudySqliteService;
        $id = $service->createEntry(
            'Central Market',
            'Whole chicken',
            'Brand A',
            12.5,
            '2026-10-13',
            'Morning check',
            7
        );

        $this->assertGreaterThan(0, $id);

        $all = $service->listEntries(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        $this->assertCount(1, $all);
        $this->assertSame('Central Market', $all[0]->market_name);
        $this->assertSame('Whole chicken', $all[0]->item_name);
        $this->assertSame('Brand A', $all[0]->brand);

        $this->assertTrue($service->updateEntry(
            $id,
            'Central Market',
            'Whole chicken',
            'Brand A',
            13.25,
            '2026-10-13',
            'Updated',
            8
        ));

        $updated = $service->find($id);
        $this->assertNotNull($updated);
        $this->assertSame(13.25, (float) $updated->price_per_kg);
        $this->assertSame(8, (int) $updated->updated_by_user_id);

        $this->assertTrue($service->deleteEntry($id));
        $this->assertCount(0, $service->listEntries());
    }

    public function test_suggest_items_returns_brand_from_past_entry(): void
    {
        $service = new MarketStudySqliteService;
        $service->createEntry('Souq A', 'Breast', 'Al-Noor', 10.0, '2026-10-10', null, 1);
        $service->createEntry('Souq B', 'Breast', 'Al-Noor', 10.5, '2026-10-11', null, 1);
        $service->createEntry('Souq A', 'Wings', 'FreshCo', 8.0, '2026-10-11', null, 1);

        $suggestions = $service->suggestItems('bre');
        $this->assertNotEmpty($suggestions);
        $this->assertSame('Breast', $suggestions[0]['item_name']);
        $this->assertSame('Al-Noor', $suggestions[0]['brand']);
        $this->assertStringContainsString('Al-Noor', $suggestions[0]['label']);
    }

    public function test_insight_stats_today_and_range_average(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00'));

        $service = new MarketStudySqliteService;
        $service->createEntry('M1', 'Item X', 'Brand Z', 10.0, '2026-10-10', null, 1);
        $service->createEntry('M1', 'Item X', 'Brand Z', 14.0, '2026-10-12', null, 1);
        $service->createEntry('M1', 'Item X', 'Brand Z', 12.0, '2026-10-13', null, 1);
        $service->createEntry('M1', 'Item X', 'Brand Z', 16.0, '2026-10-13', null, 1);

        $stats = $service->insightStats([
            'market' => 'M1',
            'item' => 'Item X',
            'brand' => 'Brand Z',
            'date_from' => '2026-10-10',
            'date_to' => '2026-10-13',
        ], '2026-10-13');

        $this->assertSame(2, $stats['today_count']);
        $this->assertEqualsWithDelta(14.0, (float) $stats['today_price'], 0.0001);
        $this->assertSame(4, $stats['range_count']);
        $this->assertEqualsWithDelta(13.0, (float) $stats['range_average'], 0.0001);
        $this->assertNotNull($stats['delta_pct']);
        $this->assertEqualsWithDelta(((14.0 - 13.0) / 13.0) * 100.0, (float) $stats['delta_pct'], 0.01);
    }

    public function test_chart_series_splits_by_brand_when_item_filtered(): void
    {
        $service = new MarketStudySqliteService;
        $service->createEntry('M1', 'Item X', 'Brand A', 10.0, '2026-10-10', null, 1);
        $service->createEntry('M1', 'Item X', 'Brand B', 12.0, '2026-10-10', null, 1);
        $service->createEntry('M1', 'Item X', 'Brand A', 11.0, '2026-10-11', null, 1);

        $chart = $service->chartSeries([
            'item' => 'Item X',
            'date_from' => '2026-10-10',
            'date_to' => '2026-10-11',
        ]);

        $this->assertSame(['2026-10-10', '2026-10-11'], $chart['labels']);
        $this->assertCount(2, $chart['datasets']);
        $labels = array_column($chart['datasets'], 'label');
        $this->assertContains('Brand A', $labels);
        $this->assertContains('Brand B', $labels);
    }
}
