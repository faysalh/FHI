<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DatabaseSynchronizeService;
use App\Services\PdaAutoSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PdaAutoSyncServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $path = storage_path('app/pda-auto-sync.json');
        if (File::exists($path)) {
            File::delete($path);
        }

        parent::tearDown();
    }

    public function test_run_if_due_skips_when_disabled(): void
    {
        $sync = Mockery::mock(DatabaseSynchronizeService::class);
        $sync->shouldNotReceive('run');

        $service = new PdaAutoSyncService($sync);

        $this->assertFalse($service->runIfDue());
    }

    public function test_run_if_due_skips_when_no_pending_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-07 10:00:00'));

        $sync = Mockery::mock(DatabaseSynchronizeService::class);
        $sync->shouldReceive('isAvailable')->andReturn(true);
        $sync->shouldReceive('countPendingPdaWork')->andReturn(0);
        $sync->shouldNotReceive('run');

        $service = new PdaAutoSyncService($sync);
        $service->saveSettings(true, 60, 'all');

        $this->assertFalse($service->runIfDue());
    }

    public function test_run_if_due_runs_when_enabled_pending_and_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-07 10:00:00'));

        $sync = Mockery::mock(DatabaseSynchronizeService::class);
        $sync->shouldReceive('isAvailable')->andReturn(true);
        $sync->shouldReceive('countPendingPdaWork')->andReturn(2);
        $sync->shouldReceive('run')
            ->once()
            ->with('scheduler', 'all');

        $service = new PdaAutoSyncService($sync);
        $service->saveSettings(true, 60, 'all');

        $this->assertTrue($service->runIfDue());

        $settings = $service->settings();
        $this->assertSame('2026-06-07 10:00:00', $settings['last_run_at']);
        $this->assertSame(2, $settings['last_pending_count']);
        $this->assertNull($settings['last_error']);
    }

    public function test_run_if_due_waits_for_interval(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-07 10:00:00'));

        $sync = Mockery::mock(DatabaseSynchronizeService::class);
        $sync->shouldReceive('isAvailable')->andReturn(true);
        $sync->shouldReceive('countPendingPdaWork')->andReturn(1);
        $sync->shouldReceive('run')->once()->with('scheduler', 'all');

        $service = new PdaAutoSyncService($sync);
        $service->saveSettings(true, 60, 'all');
        $this->assertTrue($service->runIfDue());

        Carbon::setTestNow(Carbon::parse('2026-06-07 10:00:30'));
        $this->assertFalse($service->runIfDue());
    }

    public function test_save_settings_rejects_invalid_agent(): void
    {
        $sync = Mockery::mock(DatabaseSynchronizeService::class);
        $service = new PdaAutoSyncService($sync);

        $this->expectException(RuntimeException::class);
        $service->saveSettings(true, 60, 'not-a-valid-agent');
    }

    public function test_is_due_when_never_run_before(): void
    {
        $sync = Mockery::mock(DatabaseSynchronizeService::class);
        $service = new PdaAutoSyncService($sync);
        $service->saveSettings(true, 30, 'all');

        $this->assertTrue($service->isDue($service->settings(), Carbon::parse('2026-06-07 10:00:00')));
    }
}
