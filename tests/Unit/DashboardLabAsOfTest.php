<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DashboardLabAsOf;
use Carbon\Carbon;
use Tests\TestCase;

class DashboardLabAsOfTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_resolve_defaults_to_now(): void
    {
        Carbon::setTestNow('2026-06-20 15:00:00');
        config(['reporting.dashboard_lab.historical_dates_enabled' => true]);

        $asOf = DashboardLabAsOf::resolve(null);

        $this->assertSame('2026-06-20', $asOf->toDateString());
        $this->assertTrue(DashboardLabAsOf::isLive($asOf));
    }

    public function test_resolve_parses_valid_past_date(): void
    {
        Carbon::setTestNow('2026-06-20 15:00:00');
        config(['reporting.dashboard_lab.historical_dates_enabled' => true]);

        $asOf = DashboardLabAsOf::resolve('2026-06-10');

        $this->assertSame('2026-06-10', $asOf->toDateString());
        $this->assertFalse(DashboardLabAsOf::isLive($asOf));
        $this->assertSame('10 Jun 2026', DashboardLabAsOf::daySectionLabel($asOf));
    }

    public function test_force_live_ignores_date_input(): void
    {
        Carbon::setTestNow('2026-06-20 15:00:00');
        config(['reporting.dashboard_lab.historical_dates_enabled' => true]);

        $asOf = DashboardLabAsOf::resolve('2026-06-10', true);

        $this->assertSame('2026-06-20', $asOf->toDateString());
    }

    public function test_disabled_config_always_returns_now(): void
    {
        Carbon::setTestNow('2026-06-20 15:00:00');
        config(['reporting.dashboard_lab.historical_dates_enabled' => false]);

        $asOf = DashboardLabAsOf::resolve('2026-06-10');

        $this->assertSame('2026-06-20', $asOf->toDateString());
    }
}
