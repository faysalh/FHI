<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\ComparisonReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Services\ReportAssemblyPriorityService;
use Mockery;
use Tests\TestCase;

class ComparisonReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockDependencies(?ComparisonReportRepository $repo = null): ComparisonReportRepository
    {
        $repo ??= Mockery::mock(ComparisonReportRepository::class);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn(['Baghdad']);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([
            ['id' => 's1', 'name' => 'Salesman 1'],
        ]);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);
        $assembly = Mockery::mock(ReportAssemblyPriorityService::class);
        $assembly->shouldReceive('sortRows')->andReturnUsing(static fn (array $rows): array => $rows);

        $this->app->instance(ComparisonReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);
        $this->app->instance(ReportAssemblyPriorityService::class, $assembly);

        return $repo;
    }

    public function test_comparison_report_renders_with_diff_colors(): void
    {
        $repo = Mockery::mock(ComparisonReportRepository::class);
        $repo->shouldReceive('getItemRows')
            ->twice()
            ->andReturn(
                [(object) ['category_name' => 'Cat A', 'item_name' => 'Item A', 'quantity_total' => 10, 'amount_total' => 100, 'weight_total' => 5]],
                [(object) ['category_name' => 'Cat A', 'item_name' => 'Item A', 'quantity_total' => 14, 'amount_total' => 90, 'weight_total' => 9]]
            );
        $repo->shouldReceive('getCategoryOptions')->andReturn(['Cat A']);

        $this->mockDependencies($repo);

        $response = $this->get('/reports/comparison');

        $response->assertOk();
        $response->assertSee('Comparison report');
        $response->assertSee('Difference (P2 - P1)');
        $response->assertSee('Growth %');
        $response->assertSee('Total');
        $response->assertSee('Item A');
        $response->assertSee('pos"', false);
        $response->assertSee('neg"', false);
    }

    public function test_comparison_report_passes_exclude_category_to_repository(): void
    {
        $repo = Mockery::mock(ComparisonReportRepository::class);
        $repo->shouldReceive('getItemRows')
            ->withArgs(function (string $from, string $to, array $cities, ?string $salesman, ?string $exclude) {
                return $exclude === 'Frozen';
            })
            ->twice()
            ->andReturn([]);
        $repo->shouldReceive('getCategoryOptions')->andReturn(['Fresh', 'Frozen']);

        $this->mockDependencies($repo);

        $response = $this->get('/reports/comparison?exclude_category=Frozen');

        $response->assertOk();
        $response->assertSee('Exclude category');
    }

    public function test_comparison_report_exclude_category_appears_in_dropdown(): void
    {
        $repo = Mockery::mock(ComparisonReportRepository::class);
        $repo->shouldReceive('getItemRows')->twice()->andReturn([]);
        $repo->shouldReceive('getCategoryOptions')->andReturn(['Cat A', 'Cat B']);

        $this->mockDependencies($repo);

        $response = $this->get('/reports/comparison');

        $response->assertOk();
        $response->assertSee('Cat A');
        $response->assertSee('Cat B');
        $response->assertSee('Exclude category');
    }
}
