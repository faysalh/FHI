<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\NonWorkingHolidaysSqliteService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

final class WorkingDays
{
    /**
     * Calendar days elapsed in the month through $asOf (inclusive), e.g. May 23 → 23.
     */
    public static function calendarDaysElapsedInMonth(?CarbonInterface $asOf = null): int
    {
        $asOf ??= now();

        return max(1, (int) $asOf->day);
    }

    /**
     * Working days from the 1st of the month through $asOf (inclusive), excluding Fridays only.
     */
    public static function countMonthToDateExcludingFridays(?CarbonInterface $asOf = null): int
    {
        $asOf ??= now();

        return self::countBusinessDaysBetween(
            $asOf->copy()->startOfMonth(),
            $asOf->copy()->startOfDay(),
            excludeFridays: true,
            excludeConfiguredHolidays: false
        );
    }

    /**
     * Working days in the full calendar month containing $asOf, excluding Fridays only.
     */
    public static function countFullMonthExcludingFridays(?CarbonInterface $asOf = null): int
    {
        $asOf ??= now();

        return self::countBusinessDaysBetween(
            $asOf->copy()->startOfMonth(),
            $asOf->copy()->endOfMonth()->startOfDay(),
            excludeFridays: true,
            excludeConfiguredHolidays: false
        );
    }

    /**
     * Business days month-to-date: excludes Fridays and configured Eid / holiday dates.
     */
    public static function countMonthToDateForProjection(?CarbonInterface $asOf = null): int
    {
        $asOf ??= now();

        return self::countBusinessDaysBetween(
            $asOf->copy()->startOfMonth(),
            $asOf->copy()->startOfDay(),
            excludeFridays: true,
            excludeConfiguredHolidays: true
        );
    }

    /**
     * Business days in the full month: excludes Fridays and configured Eid / holiday dates.
     */
    public static function countFullMonthForProjection(?CarbonInterface $asOf = null): int
    {
        $asOf ??= now();

        return self::countBusinessDaysBetween(
            $asOf->copy()->startOfMonth(),
            $asOf->copy()->endOfMonth()->startOfDay(),
            excludeFridays: true,
            excludeConfiguredHolidays: true
        );
    }

    public static function isBusinessDayForProjection(CarbonInterface $date): bool
    {
        return self::isBusinessDay($date, excludeFridays: true, excludeConfiguredHolidays: true);
    }

    /**
     * @return list<string> Y-m-d holiday dates for years touched by the range (plus env extras).
     */
    public static function holidayDatesBetween(CarbonInterface $start, CarbonInterface $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        $years = range((int) $start->year, (int) $end->year);
        $dates = [];

        foreach ($years as $year) {
            foreach (self::configuredHolidayDatesForYear($year) as $d) {
                $dates[$d] = true;
            }
        }

        sort($dates);

        return $dates;
    }

    /**
     * @return list<string>
     */
    public static function configuredHolidayDatesForYear(int $year): array
    {
        $merged = [];

        try {
            $service = app(NonWorkingHolidaysSqliteService::class);
            $service->ensureReady();
            foreach ($service->listDatesForYear($year) as $d) {
                $merged[$d] = true;
            }
        } catch (Throwable) {
            foreach (self::configuredHolidayDatesFromConfig($year) as $d) {
                $merged[$d] = true;
            }
        }

        foreach (self::parseHolidayDateList((string) config('reporting.non_working_holidays_extra', '')) as $raw) {
            $d = self::normalizeHolidayDate($raw);
            if ($d !== null && str_starts_with($d, (string) $year.'-')) {
                $merged[$d] = true;
            }
        }

        ksort($merged);

        return array_keys($merged);
    }

    /**
     * @return list<string>
     */
    private static function configuredHolidayDatesFromConfig(int $year): array
    {
        $byYear = config('reporting.non_working_holidays', []);
        $fromConfig = is_array($byYear[$year] ?? null) ? $byYear[$year] : [];
        $out = [];
        foreach ($fromConfig as $raw) {
            $d = self::normalizeHolidayDate((string) $raw);
            if ($d !== null) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * Working days in an inclusive sales period (Fridays excluded; holidays still count).
     */
    public static function countSalesPeriodWorkingDays(string $dateFrom, string $dateTo): int
    {
        return self::countBusinessDaysBetween(
            Carbon::parse($dateFrom)->startOfDay(),
            Carbon::parse($dateTo)->startOfDay(),
            excludeFridays: true,
            excludeConfiguredHolidays: false
        );
    }

    /**
     * Business days in an inclusive date range (Fridays and configured holidays excluded).
     */
    public static function countBusinessDaysForProjectionBetween(string $dateFrom, string $dateTo): int
    {
        return self::countBusinessDaysBetween(
            Carbon::parse($dateFrom)->startOfDay(),
            Carbon::parse($dateTo)->startOfDay(),
            excludeFridays: true,
            excludeConfiguredHolidays: true
        );
    }

    /**
     * Date of the Nth business day (Fridays and configured holidays excluded) within the
     * calendar month containing $monthAnchor. Used to build a like-for-like "same number of
     * working days" window in another month. If the month has fewer than $n business days,
     * the last day of the month is returned.
     */
    public static function nthBusinessDayOfMonthForProjection(CarbonInterface $monthAnchor, int $n): Carbon
    {
        $cursor = $monthAnchor->copy()->startOfMonth()->startOfDay();
        $end = $monthAnchor->copy()->endOfMonth()->startOfDay();

        if ($n < 1) {
            return $cursor->copy();
        }

        $count = 0;
        while ($cursor->lte($end)) {
            if (self::isBusinessDay($cursor, excludeFridays: true, excludeConfiguredHolidays: true)) {
                $count++;
                if ($count >= $n) {
                    return $cursor->copy();
                }
            }
            $cursor->addDay();
        }

        return $end->copy();
    }

    public static function countBusinessDaysBetween(
        CarbonInterface $start,
        CarbonInterface $end,
        bool $excludeFridays = true,
        bool $excludeConfiguredHolidays = true
    ): int {
        $cursor = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        if ($cursor->gt($end)) {
            return 1;
        }

        $count = 0;
        while ($cursor->lte($end)) {
            if (self::isBusinessDay($cursor, $excludeFridays, $excludeConfiguredHolidays)) {
                $count++;
            }
            $cursor->addDay();
        }

        return max(1, $count);
    }

    public static function isBusinessDay(
        CarbonInterface $date,
        bool $excludeFridays = true,
        bool $excludeConfiguredHolidays = true
    ): bool {
        if ($excludeFridays && $date->isFriday()) {
            return false;
        }

        if ($excludeConfiguredHolidays) {
            $holiday = self::configuredHolidayDatesForYear((int) $date->year);
            if (in_array($date->format('Y-m-d'), $holiday, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function parseHolidayDateList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $p): ?string => self::normalizeHolidayDate($p),
            $parts
        )));
    }

    private static function normalizeHolidayDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
