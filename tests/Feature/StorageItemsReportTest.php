<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\StorageItemsReportRepository;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class StorageItemsReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_storage_items_page_renders(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'category_name' => 'Chicken',
                'item_code' => 'IT-1',
                'item_name' => 'Whole chicken',
                'quantity_total' => 100,
                'weight_total' => 50,
                'amount_total' => 200000,
                'sold_quantity_period' => 40,
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(StorageItemsReportRepository::class);
        $repo->shouldReceive('getEvaluationTotals')->zeroOrMoreTimes()->andReturn([
            'quantity_total' => 500,
            'sold_quantity_period' => 50,
        ]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getCategoryOptions')->once()->andReturn(['Chicken']);
        $repo->shouldReceive('getEvaluationReport')
            ->once()
            ->with(
                '2026-03-10',
                null,
                null,
                [],
                null,
                '2026-03-01',
                '2026-03-10',
                1,
                250
            )
            ->andReturn($paginator);
        $this->app->instance(StorageItemsReportRepository::class, $repo);

        $response = $this->get('/reports/storage-items?sales_date_from=2026-03-01&sales_date_to=2026-03-10');

        $response->assertOk();
        $response->assertSee('Storage items report');
        $response->assertSee('Whole chicken');
        $response->assertSee('Carton');
        $response->assertSee('Sales average');
        $response->assertSee('Forecast');
        $response->assertSee('Working days (Fri excluded)');
        $response->assertSee('>9<', false);
        $response->assertDontSee('As of date (inventory)', false);
    }

    public function test_storage_items_page_accepts_sales_period_storage_and_category(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(StorageItemsReportRepository::class);
        $repo->shouldReceive('getEvaluationTotals')->zeroOrMoreTimes()->andReturn([
            'quantity_total' => 500,
            'sold_quantity_period' => 50,
        ]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getCategoryOptions')->once()->andReturn(['Chicken']);
        $repo->shouldReceive('getEvaluationReport')
            ->once()
            ->with(
                '2026-03-10',
                'Main Store',
                'Chicken',
                [],
                'whole',
                '2026-03-01',
                '2026-03-13',
                1,
                250
            )
            ->andReturn($paginator);
        $this->app->instance(StorageItemsReportRepository::class, $repo);

        $response = $this->get('/reports/storage-items?sales_date_from=2026-03-01&sales_date_to=2026-03-13&category=Chicken&item=whole&storage=Main%20Store');

        $response->assertOk();
        $response->assertSee('Carton');
    }

    public function test_storage_items_evaluation_page_shows_weight_price_and_export(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'category_name' => 'Chicken',
                'item_code' => 'IT-1',
                'item_name' => 'Whole chicken',
                'quantity_total' => 12,
                'weight_total' => 18,
                'amount_total' => 240000,
                'sold_quantity_period' => 6,
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(StorageItemsReportRepository::class);
        $repo->shouldReceive('getEvaluationTotals')->zeroOrMoreTimes()->andReturn([
            'quantity_total' => 500,
            'sold_quantity_period' => 50,
        ]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getCategoryOptions')->once()->andReturn(['Chicken']);
        $repo->shouldReceive('getEvaluationReport')->once()->andReturn($paginator);
        $this->app->instance(StorageItemsReportRepository::class, $repo);

        $response = $this->get('/reports/storage-items/evaluation?sales_date_from=2026-03-01&sales_date_to=2026-03-10');

        $response->assertOk();
        $response->assertSee('Storage items — evaluation');
        $response->assertSee('Price/KG (IQD)');
        $response->assertSee('Total value (IQD)');
        $response->assertSee('Export evaluation CSV');
    }

    public function test_storage_items_passes_exclude_categories_to_repository(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(StorageItemsReportRepository::class);
        $repo->shouldReceive('getEvaluationTotals')->zeroOrMoreTimes()->andReturn([
            'quantity_total' => 500,
            'sold_quantity_period' => 50,
        ]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn([]);
        $repo->shouldReceive('getCategoryOptions')->once()->andReturn(['Chicken', 'Frozen']);
        $repo->shouldReceive('getEvaluationReport')
            ->once()
            ->with(
                '2026-03-10',
                null,
                'Chicken',
                ['Frozen'],
                null,
                '2026-03-01',
                '2026-03-10',
                1,
                250
            )
            ->andReturn($paginator);
        $this->app->instance(StorageItemsReportRepository::class, $repo);

        $response = $this->get('/reports/storage-items?sales_date_from=2026-03-01&sales_date_to=2026-03-10&category=Chicken&exclude_categories[]=Frozen');

        $response->assertOk();
    }

    public function test_storage_items_passes_multiple_exclude_categories(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(StorageItemsReportRepository::class);
        $repo->shouldReceive('getEvaluationTotals')->zeroOrMoreTimes()->andReturn([
            'quantity_total' => 500,
            'sold_quantity_period' => 50,
        ]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn([]);
        $repo->shouldReceive('getCategoryOptions')->once()->andReturn(['Chicken', 'Frozen', 'Dairy']);
        $repo->shouldReceive('getEvaluationReport')
            ->once()
            ->with(
                '2026-03-10',
                null,
                null,
                ['Frozen', 'Dairy'],
                null,
                '2026-03-10',
                '2026-03-10',
                1,
                250
            )
            ->andReturn($paginator);
        $this->app->instance(StorageItemsReportRepository::class, $repo);

        $response = $this->get('/reports/storage-items?sales_date_to=2026-03-10&exclude_categories[]=Frozen&exclude_categories[]=Dairy');

        $response->assertOk();
    }

    public function test_storage_items_rejects_same_category_and_exclude(): void
    {
        $response = $this->get('/reports/storage-items?category=Chicken&exclude_categories[]=Chicken');

        $response->assertSessionHasErrors(['exclude_categories']);
    }
}
