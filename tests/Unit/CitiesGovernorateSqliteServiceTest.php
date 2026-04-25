<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CitiesGovernorateSqliteService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CitiesGovernorateSqliteServiceTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = storage_path('framework/testing/cities-governorates-'.uniqid('', true).'.sqlite');
        if (! is_dir(dirname($this->databasePath))) {
            mkdir(dirname($this->databasePath), 0777, true);
        }
        touch($this->databasePath);

        config()->set('database.connections.deliveries_sqlite.database', $this->databasePath);
        DB::purge('deliveries_sqlite');
    }

    protected function tearDown(): void
    {
        DB::purge('deliveries_sqlite');
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_it_saves_and_lists_governorates(): void
    {
        $service = app(CitiesGovernorateSqliteService::class);

        $id = $service->saveGovernorate(null, 'Duhok', 'Duhok', ['Akre']);

        $this->assertGreaterThan(0, $id);
        $this->assertSame('Duhok', $service->listGovernorates()[0]->name);
        $this->assertSame(['Akre', 'Duhok'], $service->getGovernorateById($id)['members']);
    }

    public function test_it_updates_existing_name_case_insensitively(): void
    {
        $service = app(CitiesGovernorateSqliteService::class);

        $firstId = $service->saveGovernorate(null, 'Duhok', 'Duhok', []);
        $secondId = $service->saveGovernorate(null, 'duhok', 'Duhok City', []);

        $this->assertSame($firstId, $secondId);
        $this->assertCount(1, $service->listGovernorates());
        $this->assertSame('Duhok City', $service->getGovernorateById($firstId)['governorate_city']);
    }

    public function test_stale_edit_id_falls_back_to_insert_or_name_upsert(): void
    {
        $service = app(CitiesGovernorateSqliteService::class);

        $id = $service->saveGovernorate(999, 'Duhok', 'Duhok', []);

        $this->assertGreaterThan(0, $id);
        $this->assertSame('Duhok', $service->getGovernorateById($id)['name']);
    }
}
