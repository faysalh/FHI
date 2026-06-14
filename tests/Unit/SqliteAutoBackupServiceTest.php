<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LocalSqliteBackupService;
use App\Services\SqliteAutoBackupService;
use App\Support\SqliteAutoBackupSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class SqliteAutoBackupServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $path = storage_path('app/sqlite-auto-backup.json');
        if (File::exists($path)) {
            File::delete($path);
        }

        parent::tearDown();
    }

    public function test_run_if_due_skips_when_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-07 03:00:00'));

        $backups = Mockery::mock(LocalSqliteBackupService::class);
        $backups->shouldNotReceive('createBackup');

        $service = new SqliteAutoBackupService($backups);

        $this->assertFalse($service->runIfDue());
    }

    public function test_run_if_due_runs_once_per_day_after_scheduled_time(): void
    {
        $directory = storage_path('app/test-auto-backup-dir');
        File::ensureDirectoryExists($directory);

        Carbon::setTestNow(Carbon::parse('2026-06-07 02:30:00'));

        $backups = Mockery::mock(LocalSqliteBackupService::class);
        $backups->shouldReceive('createBackup')
            ->once()
            ->with('all', SqliteAutoBackupSettings::normalizeDirectory($directory))
            ->andReturn(['filename' => 'all-20260607-023000.zip', 'path' => $directory.'/all.zip', 'label' => 'All local databases']);

        $service = new SqliteAutoBackupService($backups);
        $service->saveSettings(true, $directory, '02:00');

        $this->assertTrue($service->runIfDue());
        $this->assertFalse($service->runIfDue());

        File::deleteDirectory($directory);
    }

    public function test_run_if_due_waits_until_scheduled_time(): void
    {
        $directory = storage_path('app/test-auto-backup-dir');
        File::ensureDirectoryExists($directory);

        Carbon::setTestNow(Carbon::parse('2026-06-07 01:30:00'));

        $backups = Mockery::mock(LocalSqliteBackupService::class);
        $backups->shouldNotReceive('createBackup');

        $service = new SqliteAutoBackupService($backups);
        $service->saveSettings(true, $directory, '02:00');

        $this->assertFalse($service->runIfDue());

        File::deleteDirectory($directory);
    }
}
