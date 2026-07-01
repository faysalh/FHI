<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\ReportAssemblyPriorityService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class SalesByItemReportTest extends TestCase
{
    private const SALESMAN_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_page_prompts_for_salesman_when_missing(): void
    {
        $this->bindMocks(needsReport: false);

        $response = $this->get('/reports/sales-by-item?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertSee('Sales by item');
        $response->assertSee('Select a salesman');
    }

    public function test_page_renders_category_price_tier_matrix(): void
    {
        $this->bindMocks(needsReport: true);

        $response = $this->get('/reports/sales-by-item?date_from=2026-04-01&date_to=2026-04-20&salesman_id='.self::SALESMAN_ID);

        $response->assertOk();
        $response->assertSee('صدر مسحب');
        $response->assertSee('Price 1');
        $response->assertSee('Price groups');
    }

    public function test_page_renders_subset_when_price_tiers_filtered(): void
    {
        $this->bindMocks(needsReport: true);

        $response = $this->get('/reports/sales-by-item?date_from=2026-04-01&date_to=2026-04-20&salesman_id='.self::SALESMAN_ID.'&price_tiers[]=3&price_tiers[]=4');

        $response->assertOk();
        $response->assertSee('Price 3 (ماركيت)');
        $response->assertSee('Price 4 (جملة)');
        $response->assertDontSee('Unknown group');
    }

    public function test_page_renders_subset_when_metrics_filtered(): void
    {
        $this->bindMocks(needsReport: true);

        $response = $this->get('/reports/sales-by-item?date_from=2026-04-01&date_to=2026-04-20&salesman_id='.self::SALESMAN_ID.'&metrics[]=qty&metrics[]=amt');

        $response->assertOk();
        $response->assertSee('<th class="num">Qty</th>', false);
        $response->assertSee('<th class="num">Amount</th>', false);
        $response->assertDontSee('<th class="num">Weight</th>', false);
    }

    public function test_export_csv_requires_salesman(): void
    {
        $this->bindMocks(needsReport: false);

        $response = $this->get('/reports/sales-by-item/export/csv?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertRedirect('/reports/sales-by-item?date_from=2026-04-01&date_to=2026-04-20');
        $response->assertSessionHas('error', 'Choose a salesman before exporting.');
    }

    private function bindMocks(bool $needsReport): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'category_name' => 'صدر مسحب',
                'p1_qty' => 10,
                'p1_amt' => 100,
                'p1_wt' => 5,
                'p2_qty' => 0,
                'p2_amt' => 0,
                'p2_wt' => 0,
                'p3_qty' => 0,
                'p3_amt' => 0,
                'p3_wt' => 0,
                'p4_qty' => 0,
                'p4_amt' => 0,
                'p4_wt' => 0,
                'p5_qty' => 0,
                'p5_amt' => 0,
                'p5_wt' => 0,
                'unmatched_qty' => 1,
                'unmatched_amt' => 10,
                'unmatched_wt' => 0.5,
                'total_qty' => 11,
                'total_amt' => 110,
                'total_wt' => 5.5,
            ]],
            total: 1,
            perPage: 250,
            currentPage: 1
        );

        $repo = Mockery::mock(SalesByItemReportRepository::class);
        $repo->shouldReceive('normalizeSalesmanId')
            ->andReturnUsing(static fn (?string $id): ?string => $id === self::SALESMAN_ID ? self::SALESMAN_ID : null);
        $repo->shouldReceive('normalizeCategories')->andReturn([]);
        $repo->shouldReceive('normalizePriceTiers')->andReturn([]);
        $repo->shouldReceive('getCategoryOptions')->andReturn(['صدر مسحب']);

        if ($needsReport) {
            $repo->shouldReceive('getReport')->once()->andReturn($paginator);
            $repo->shouldReceive('getGrandTotals')->once()->andReturn((object) ['total_qty' => 11, 'total_amt' => 110, 'total_wt' => 5.5]);
        }

        $sales = Mockery::mock(SalesReportRepository::class);
        $sales->shouldReceive('normalizeCities')->andReturn([]);
        $sales->shouldReceive('getStorageOptions')->andReturn(['Main']);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([
            ['id' => self::SALESMAN_ID, 'name' => 'Test Salesman'],
        ]);
        $visits->shouldReceive('getCityOptions')->andReturn([]);
        $visits->shouldReceive('getAccountCityColumnName')->andReturn('fld_city');

        $assembly = Mockery::mock(ReportAssemblyPriorityService::class);
        $assembly->shouldReceive('sortRows')->andReturnUsing(static fn (array $rows): array => $rows);

        $this->app->instance(SalesByItemReportRepository::class, $repo);
        $this->app->instance(SalesReportRepository::class, $sales);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(ReportAssemblyPriorityService::class, $assembly);
    }
}
