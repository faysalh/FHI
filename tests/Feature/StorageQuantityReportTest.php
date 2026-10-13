<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\StorageQuantityReportRepository;
use App\Services\ReportAssemblyPriorityService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class StorageQuantityReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_storage_quantity_page_renders(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'category_name' => 'Chicken',
                'item_code' => '82',
                'item_name' => 'Wings',
                'storage_name' => 'Main',
                'balance_mode' => 'normal',
                'balance' => 100.0,
                'in_store' => 90.0,
            ]],
            total: 1,
            perPage: 250,
            currentPage: 1
        );

        $repo = Mockery::mock(StorageQuantityReportRepository::class);
        $repo->shouldReceive('getYearOptions')->once()->andReturn([(object) [
            'year_id' => 'year-1',
            'year_name' => '2026',
            'is_current' => 1,
        ]]);
        $repo->shouldReceive('getStorageOptions')->once()->andReturn(['Main']);
        $repo->shouldReceive('getCategoryOptions')->once()->andReturn(['Chicken']);
        $repo->shouldReceive('getItemOptions')->once()->with([])->andReturn([]);
        $repo->shouldReceive('getStoreTitleOptions')->once()->andReturn([]);
        $repo->shouldReceive('normalizeStringList')->andReturnUsing(static fn (?array $v): array => array_values(array_filter((array) $v)));
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);
        $repo->shouldReceive('exportRows')->once()->andReturn($paginator->items());
        $repo->shouldReceive('totalsFromRows')->once()->andReturn([
            'balance_total' => 100.0,
            'in_store_total' => 90.0,
        ]);
        $this->app->instance(StorageQuantityReportRepository::class, $repo);

        $assembly = Mockery::mock(ReportAssemblyPriorityService::class);
        $assembly->shouldReceive('sortRows')->andReturnUsing(static fn (array $rows): array => $rows);
        $this->app->instance(ReportAssemblyPriorityService::class, $assembly);

        $response = $this->get('/reports/storage-quantity?balance_mode=normal&year_id=year-1');

        $response->assertOk();
        $response->assertSee('Storage quantity');
        $response->assertSee('Normal (SP_Get_Item_Balance)');
        $response->assertSee('Wings');
        $response->assertSee('In store');
    }

    public function test_invalid_balance_mode_rejected(): void
    {
        $response = $this->get('/reports/storage-quantity?balance_mode=invalid&year_id=year-1');

        $response->assertSessionHasErrors('balance_mode');
    }
}
