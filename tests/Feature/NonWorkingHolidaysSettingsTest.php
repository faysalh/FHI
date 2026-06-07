<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NonWorkingHolidaysSqliteService;
use App\Support\WorkingDays;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class NonWorkingHolidaysSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.deliveries_sqlite.database', ':memory:');
    }

    public function test_holidays_settings_page_renders(): void
    {
        $response = $this->get('/reports/holidays');

        $response->assertOk();
        $response->assertSee('Holidays');
        $response->assertSee('Add non-working day');
    }

    public function test_adding_holiday_excludes_it_from_working_days(): void
    {
        $service = new NonWorkingHolidaysSqliteService;
        $service->ensureReady();
        $service->addHoliday('2026-06-10', 'Test Eid');

        Config::set('reporting.non_working_holidays', []);
        Config::set('reporting.non_working_holidays_extra', '');

        $asOf = Carbon::create(2026, 6, 10);
        $this->assertFalse(WorkingDays::isBusinessDayForProjection($asOf));
    }
}
