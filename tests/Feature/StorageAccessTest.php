<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\StorageReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use App\Services\ReportsUsersSqliteService;
use App\Support\ReportAuthSession;
use App\Support\StorageReportAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class StorageAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_restricted_storages_are_enforced_when_filter_is_locked(): void
    {
        ReportAuthSession::login(9, 'storage-user', false, ['storage'], null, new StorageReportAccess(
            canFilterStorage: false,
            allowedStorages: ['Warehouse A', 'Warehouse B'],
        ));

        $paginator = new LengthAwarePaginator(items: [], total: 0, perPage: 250, currentPage: 1);

        $repo = Mockery::mock(StorageReportRepository::class);
        $repo->shouldReceive('getStorageOptions')->andReturn(['Warehouse A', 'Warehouse B', 'Warehouse C']);
        $repo->shouldReceive('getCategoryOptions')->andReturn([]);
        $repo->shouldReceive('getItemOptions')->andReturn([]);
        $repo->shouldReceive('getStoreCityOptions')->andReturn([]);
        $repo->shouldReceive('hasStoreCityColumn')->andReturn(false);
        $repo->shouldReceive('normalizeStringList')->andReturnUsing(static fn (?array $v): array => array_values(array_filter((array) $v)));
        $repo->shouldReceive('getReport')
            ->once()
            ->with(
                '2026-05-17',
                ['Warehouse A', 'Warehouse B'],
                [],
                [],
                [],
                [],
                [],
                1,
                250
            )
            ->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')
            ->once()
            ->with('2026-05-17', ['Warehouse A', 'Warehouse B'], [], [], [], [], [])
            ->andReturn(['quantity_total' => 0.0, 'weight_total' => 0.0]);
        $repo->shouldReceive('getCategoryTotals')
            ->once()
            ->andReturn([]);

        $gov = Mockery::mock(CitiesGovernorateSqliteService::class);
        $gov->shouldReceive('listGovernorates')->andReturn([]);

        $this->app->instance(StorageReportRepository::class, $repo);
        $this->app->instance(CitiesGovernorateSqliteService::class, $gov);

        $response = $this->get('/reports/storage?as_of_date=2026-05-17&storages[]=Warehouse+C');

        $response->assertOk();
        $response->assertSee('Warehouse A, Warehouse B (assigned for your account)', false);
    }

    public function test_storage_access_is_saved_for_report_user(): void
    {
        $users = Mockery::mock(ReportsUsersSqliteService::class);
        $users->shouldReceive('ensureReady');
        $users->shouldReceive('createUser')
            ->once()
            ->withArgs(function (
                string $username,
                string $password,
                bool $isSuperAdmin,
                array $keys,
                $deliveriesAccess,
                ?StorageReportAccess $storageAccess
            ): bool {
                return $username === 'stock1'
                    && $password === 'secret12'
                    && $isSuperAdmin === false
                    && $keys === ['storage']
                    && $storageAccess instanceof StorageReportAccess
                    && $storageAccess->canFilterStorage === false
                    && $storageAccess->allowedStorages === ['Warehouse A'];
            })
            ->andReturn(15);

        $storageRepo = Mockery::mock(StorageReportRepository::class);
        $storageRepo->shouldReceive('getStorageOptions')->andReturn(['Warehouse A']);

        $this->app->instance(ReportsUsersSqliteService::class, $users);
        $this->app->instance(StorageReportRepository::class, $storageRepo);

        $response = $this->post(route('reports.users.store'), [
            'username' => 'stock1',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'report_keys' => ['storage'],
            'storage_allowed_storages' => ['Warehouse A'],
        ]);

        $response->assertRedirect(route('reports.users.index'));
        $response->assertSessionHas('status', 'User created.');
    }
}
