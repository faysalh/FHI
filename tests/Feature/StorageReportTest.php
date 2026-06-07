<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\StorageReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class StorageReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_storage_page_renders(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'category_name' => 'Chicken',
                'item_code' => 'IT-1',
                'item_name' => 'Whole chicken',
                'quantity_total' => 100,
                'weight_total' => 50,
            ]],
            total: 1,
            perPage: 250,
            currentPage: 1
        );

        $repo = Mockery::mock(StorageReportRepository::class);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getCategoryOptions')->once()->andReturn(['Chicken']);
        $repo->shouldReceive('getItemOptions')->once()->with([])->andReturn([]);
        $repo->shouldReceive('getStoreCityOptions')->once()->andReturn(['Erbil']);
        $repo->shouldReceive('hasStoreCityColumn')->once()->andReturn(true);
        $repo->shouldReceive('normalizeStringList')->andReturnUsing(static fn (?array $v): array => array_values(array_filter((array) $v)));
        $repo->shouldReceive('getReport')
            ->once()
            ->with('2026-05-17', [], [], [], [], [], [], 1, 250)
            ->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')
            ->once()
            ->with('2026-05-17', [], [], [], [], [], [])
            ->andReturn(['quantity_total' => 100.0, 'weight_total' => 50.0]);
        $repo->shouldReceive('getCategoryTotals')
            ->once()
            ->with('2026-05-17', [], [], [], [], [], [])
            ->andReturn([(object) ['category_name' => 'Chicken', 'quantity_total' => 100, 'weight_total' => 50]]);
        $this->app->instance(StorageReportRepository::class, $repo);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->once()->andReturn([]);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get('/reports/storage?as_of_date=2026-05-17');

        $response->assertOk();
        $response->assertSee('Storage report');
        $response->assertSee('Whole chicken');
        $response->assertSee('Weight (kg)');
        $response->assertSee('Total quantity (carton)');
        $response->assertSee('Total weight (kg)');
        $response->assertSee('Chicken — subtotal');
        $response->assertSee('Filters');
        $response->assertSee('Columns');
        $response->assertDontSee('IT-1');
    }

    public function test_storage_page_shows_optional_columns_when_enabled(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'category_name' => 'Chicken',
                'item_code' => 'IT-1',
                'item_name' => 'Whole chicken',
                'quantity_total' => 100,
                'weight_total' => 50,
            ]],
            total: 1,
            perPage: 250,
            currentPage: 1
        );

        $repo = Mockery::mock(StorageReportRepository::class);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getCategoryOptions')->once()->andReturn(['Chicken']);
        $repo->shouldReceive('getItemOptions')->once()->with([])->andReturn([]);
        $repo->shouldReceive('getStoreCityOptions')->once()->andReturn(['Erbil']);
        $repo->shouldReceive('hasStoreCityColumn')->once()->andReturn(true);
        $repo->shouldReceive('normalizeStringList')->andReturnUsing(static fn (?array $v): array => array_values(array_filter((array) $v)));
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')->once()->andReturn(['quantity_total' => 100.0, 'weight_total' => 50.0]);
        $repo->shouldReceive('getCategoryTotals')->once()->andReturn([(object) ['category_name' => 'Chicken', 'quantity_total' => 100, 'weight_total' => 50]]);
        $this->app->instance(StorageReportRepository::class, $repo);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->once()->andReturn([]);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get('/reports/storage?as_of_date=2026-05-17&show_category=1&show_item_code=1');

        $response->assertOk();
        $response->assertSee('IT-1');
        $response->assertSee('>Category</th>', false);
        $response->assertSee('>Item code</th>', false);
    }

    public function test_storage_page_accepts_multi_filters(): void
    {
        $paginator = new LengthAwarePaginator(items: [], total: 0, perPage: 250, currentPage: 1);

        $repo = Mockery::mock(StorageReportRepository::class);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getCategoryOptions')->once()->andReturn(['Chicken']);
        $repo->shouldReceive('getItemOptions')->once()->with(['Chicken'])->andReturn([]);
        $repo->shouldReceive('getStoreCityOptions')->once()->andReturn(['Erbil']);
        $repo->shouldReceive('hasStoreCityColumn')->once()->andReturn(true);
        $repo->shouldReceive('normalizeStringList')->andReturnUsing(static function (?array $v): array {
            return array_values(array_filter((array) $v));
        });
        $repo->shouldReceive('getReport')
            ->once()
            ->with(
                '2026-05-17',
                ['Main Store'],
                ['Chicken'],
                ['Wings'],
                ['item-guid-1'],
                ['item-guid-2'],
                ['Erbil'],
                1,
                250
            )
            ->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')
            ->once()
            ->with(
                '2026-05-17',
                ['Main Store'],
                ['Chicken'],
                ['Wings'],
                ['item-guid-1'],
                ['item-guid-2'],
                ['Erbil']
            )
            ->andReturn(['quantity_total' => 0.0, 'weight_total' => 0.0]);
        $repo->shouldReceive('getCategoryTotals')
            ->once()
            ->andReturn([]);
        $this->app->instance(StorageReportRepository::class, $repo);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->once()->andReturn([]);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $query = http_build_query([
            'as_of_date' => '2026-05-17',
            'storages' => ['Main Store'],
            'categories' => ['Chicken'],
            'exclude_categories' => ['Wings'],
            'items' => ['item-guid-1'],
            'exclude_items' => ['item-guid-2'],
            'cities' => ['Erbil'],
        ]);

        $response = $this->get('/reports/storage?'.$query);

        $response->assertOk();
    }

    public function test_storage_rejects_overlapping_category_include_and_exclude(): void
    {
        $response = $this->get('/reports/storage?as_of_date=2026-05-17&categories[]=Chicken&exclude_categories[]=Chicken');

        $response->assertRedirect();
        $response->assertSessionHasErrors('exclude_categories');
    }
}
