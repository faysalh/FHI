<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\RankingsReportRepository;
use App\Repositories\SalesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use Mockery;
use Tests\TestCase;

class RankingsReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_rankings_page_renders_clients_tab(): void
    {
        $rankings = Mockery::mock(RankingsReportRepository::class);
        $rankings->shouldReceive('normalizeTab')->andReturn('clients');
        $rankings->shouldReceive('normalizeMetric')->andReturn('amount');
        $rankings->shouldReceive('normalizeLimit')->andReturn(10);
        $rankings->shouldReceive('getStorageOptions')->once()->andReturn([]);
        $rankings->shouldReceive('getRankings')
            ->once()
            ->andReturn([
                'rows' => [(object) [
                    'label' => 'Client Alpha',
                    'client_code' => 'C-1',
                    'amount' => 5000,
                    'quantity' => 100,
                    'weight_total' => 50,
                    'invoice_count' => 3,
                    'share_pct' => 62.5,
                ]],
                'period_totals' => (object) [
                    'amount' => 8000,
                    'quantity' => 150,
                    'weight_total' => 80,
                    'invoice_count' => 5,
                ],
                'prior_period_label' => null,
                'prior_date_from' => null,
                'prior_date_to' => null,
            ]);

        $sales = Mockery::mock(SalesReportRepository::class);
        $sales->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $sales->shouldReceive('normalizeCities')->andReturn([]);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);

        $this->app->instance(RankingsReportRepository::class, $rankings);
        $this->app->instance(SalesReportRepository::class, $sales);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get('/reports/rankings?tab=clients&date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertSee('Rankings');
        $response->assertSee('Client Alpha');
        $response->assertSee('Growing');
        $response->assertSee('Declining');
    }

    public function test_growing_tab_shows_prior_period_hint(): void
    {
        $rankings = Mockery::mock(RankingsReportRepository::class);
        $rankings->shouldReceive('normalizeTab')->andReturn('growing');
        $rankings->shouldReceive('normalizeMetric')->andReturn('amount');
        $rankings->shouldReceive('normalizeLimit')->andReturn(10);
        $rankings->shouldReceive('getStorageOptions')->once()->andReturn([]);
        $rankings->shouldReceive('getRankings')
            ->once()
            ->andReturn([
                'rows' => [(object) [
                    'label' => 'Growing Client',
                    'client_code' => 'C-9',
                    'amount' => 3000,
                    'prior_amount' => 1000,
                    'growth_pct' => 200.0,
                    'quantity' => 20,
                    'weight_total' => 10,
                    'invoice_count' => 2,
                    'share_pct' => 30,
                ]],
                'period_totals' => (object) ['amount' => 10000, 'quantity' => 0, 'weight_total' => 0, 'invoice_count' => 0],
                'prior_period_label' => '11 Mar 2026 — 31 Mar 2026',
                'prior_date_from' => '2026-03-11',
                'prior_date_to' => '2026-03-31',
            ]);

        $sales = Mockery::mock(SalesReportRepository::class);
        $sales->shouldReceive('normalizeSalesmanIds')->andReturn([]);
        $sales->shouldReceive('normalizeCities')->andReturn([]);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getSalesmanOptions')->andReturn([]);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);

        $this->app->instance(RankingsReportRepository::class, $rankings);
        $this->app->instance(SalesReportRepository::class, $sales);
        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get('/reports/rankings?tab=growing&date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertSee('Prior period:');
        $response->assertSee('Growing Client');
        $response->assertSee('200');
    }
}
