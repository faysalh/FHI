<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\VisitsReportRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class VisitsReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_visits_report_page_renders_with_mocked_repository(): void
    {
        $mock = Mockery::mock(VisitsReportRepository::class);
        $mock->shouldReceive('monthSegmentsInRange')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('string'))
            ->andReturn([
                [
                    'key' => '2026-04',
                    'label' => 'April 2026',
                    'label_en' => 'April 2026',
                    'from' => '2026-03-19',
                    'to' => '2026-04-18',
                    'sql_alias' => 'visit_2026_04',
                ],
            ]);
        $mock->shouldReceive('paginateVisits')->once()->andReturn(
            new LengthAwarePaginator([], 0, 25, 1, ['path' => '/reports/visits'])
        );
        $mock->shouldReceive('getSalesmanOptions')->once()->andReturn([]);
        $mock->shouldReceive('getCityOptions')->once()->andReturn(['اربيل', 'عقرة']);

        $this->app->instance(VisitsReportRepository::class, $mock);

        $response = $this->get(route('reports.visits.index'));

        $response->assertOk();
        $response->assertSee('Visits report', false);
        $response->assertSee('Identifier', false);
    }
}
