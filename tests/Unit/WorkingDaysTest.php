<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WorkingDays;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingDaysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.deliveries_sqlite.database', ':memory:');
    }

    #[Test]
    public function may_23_2026_has_nineteen_working_days_excluding_fridays(): void
    {
        $asOf = Carbon::create(2026, 5, 23);

        $this->assertSame(23, WorkingDays::calendarDaysElapsedInMonth($asOf));
        $this->assertSame(19, WorkingDays::countMonthToDateExcludingFridays($asOf));
    }

    #[Test]
    public function first_day_of_month_counts_one_when_not_friday(): void
    {
        $asOf = Carbon::create(2026, 5, 1);

        $this->assertSame(1, WorkingDays::calendarDaysElapsedInMonth($asOf));
        $this->assertSame(1, WorkingDays::countMonthToDateExcludingFridays($asOf));
    }

    public function test_projection_excludes_eid_holidays_elapsed_in_month(): void
    {
        config([
            'reporting.non_working_holidays' => [
                '2026' => ['2026-03-20', '2026-03-21', '2026-03-22', '2026-03-23'],
            ],
            'reporting.non_working_holidays_extra' => '',
        ]);

        $asOf = Carbon::create(2026, 3, 23);
        $fridaysOnly = WorkingDays::countMonthToDateExcludingFridays($asOf);
        $withEid = WorkingDays::countMonthToDateForProjection($asOf);

        $this->assertSame(20, $fridaysOnly);
        $this->assertSame(17, $withEid);
    }

    #[Test]
    public function sales_period_working_days_exclude_fridays_only(): void
    {
        $this->assertSame(9, WorkingDays::countSalesPeriodWorkingDays('2026-03-01', '2026-03-10'));
        $this->assertSame(11, WorkingDays::countSalesPeriodWorkingDays('2026-03-01', '2026-03-13'));
    }

    #[Test]
    public function date_range_business_days_exclude_fridays(): void
    {
        config([
            'reporting.non_working_holidays' => ['2026' => []],
            'reporting.non_working_holidays_extra' => '',
        ]);

        $this->assertSame(16, WorkingDays::countBusinessDaysForProjectionBetween('2026-04-01', '2026-04-19'));
    }

    #[Test]
    public function nth_business_day_of_month_skips_fridays_and_clamps_to_month_end(): void
    {
        config([
            'reporting.non_working_holidays' => ['2026' => []],
            'reporting.non_working_holidays_extra' => '',
        ]);

        // May 2026: the 1st is a Friday; Fridays fall on the 1st, 8th, 15th, 22nd, 29th.
        $anchor = Carbon::create(2026, 5, 15);

        // 1st business day = May 2 (May 1 is skipped as a Friday).
        $this->assertSame('2026-05-02', WorkingDays::nthBusinessDayOfMonthForProjection($anchor, 1)->toDateString());
        // 13th business day = May 16 (skipping Fri May 1, 8, 15).
        $this->assertSame('2026-05-16', WorkingDays::nthBusinessDayOfMonthForProjection($anchor, 13)->toDateString());
        // Beyond the month's business days clamps to the last day of the month.
        $this->assertSame('2026-05-31', WorkingDays::nthBusinessDayOfMonthForProjection($anchor, 999)->toDateString());
    }
}
