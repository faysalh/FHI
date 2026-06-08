<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class EnsureReportPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.deliveries_sqlite.database', ':memory:');
        $this->app['env'] = 'local';
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_governorates_requires_explicit_permission_not_cities(): void
    {
        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 2,
            'reports_username' => 'cities-only',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['cities'],
        ])->get('/reports/governorates');

        $response->assertForbidden();
    }

    public function test_governorates_allowed_with_explicit_permission(): void
    {
        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->andReturn(['Erbil']);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);
        $gov->shouldReceive('getGovernorateById')->andReturn(null);

        $this->app->instance(VisitsReportRepository::class, $visits);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 3,
            'reports_username' => 'settings',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['governorates'],
        ])->get('/reports/governorates');

        $response->assertOk();
    }
}
