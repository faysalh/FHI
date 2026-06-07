<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CitiesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class GovernoratesSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.deliveries_sqlite.database', ':memory:');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_governorates_settings_page_renders(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn(['Erbil', 'Duhok']);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);
        $gov->shouldReceive('getGovernorateById')->andReturn(null);

        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get('/reports/governorates');

        $response->assertOk();
        $response->assertSee('Saved governorates');
        $response->assertSee('Save governorate');
    }

    public function test_saving_governorate_redirects_to_settings(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn(['Erbil']);

        $repo = Mockery::mock(CitiesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn(['Shaqlawa']);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('saveGovernorate')
            ->once()
            ->with(null, 'Erbil Governorate', 'Erbil', ['Shaqlawa'])
            ->andReturn(3);

        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesReportRepository::class, $repo);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->post('/reports/governorates', [
            'governorate_name' => 'Erbil Governorate',
            'governorate_city' => 'Erbil',
            'governorate_members' => ['Shaqlawa'],
        ]);

        $response->assertRedirect(route('reports.governorates.index', ['edit' => 3]));
    }
}
