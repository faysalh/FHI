<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class PromotionsWeekdays
{
    public const FRIDAY = 5;

    /** Saturday — first day of the promotions week. */
    public const WEEK_START_DAY = CarbonInterface::SATURDAY;

    /** Thursday — last day of the promotions week. */
    public const WEEK_END_DAY = CarbonInterface::THURSDAY;

    /** Days from Saturday through Thursday (Friday excluded). */
    public const WEEK_LENGTH_DAYS = 6;

    public const MIN_VISITS_PER_WEEK = 1;

    public const MAX_VISITS_PER_WEEK = 3;

    /**
     * Visit-day checkboxes and assignments: Saturday through Thursday (no Friday).
     *
     * @return list<int> 0=Sunday … 6=Saturday
     */
    public static function allowedWeekdayNumbers(): array
    {
        return [6, 0, 1, 2, 3, 4];
    }

    public static function label(int $weekday): string
    {
        return match ($weekday) {
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            6 => 'Saturday',
            default => 'Day '.$weekday,
        };
    }

    /**
     * @param  list<int|string>  $raw
     * @return list<int>
     */
    public static function normalizeList(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            $day = (int) $value;
            if ($day === self::FRIDAY || $day < 0 || $day > 6) {
                continue;
            }
            $out[$day] = $day;
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * @param  list<int>  $days
     */
    public static function isDailyVisitSchedule(array $days): bool
    {
        $normalized = self::normalizeList($days);

        return count($normalized) === count(self::allowedWeekdayNumbers());
    }

    /**
     * @param  list<int|string>  $visitDays
     * @return list<int>
     */
    public static function resolveVisitDaysFromInput(array $visitDays, bool $dailyVisits): array
    {
        if ($dailyVisits) {
            return self::allowedWeekdayNumbers();
        }

        return self::normalizeList($visitDays);
    }

    /**
     * @param  list<int>  $days
     */
    public static function validateVisitDays(array $days, bool $dailyVisits): void
    {
        $normalized = self::normalizeList($days);

        if ($dailyVisits) {
            if (count($normalized) !== count(self::allowedWeekdayNumbers())) {
                throw new \InvalidArgumentException('Daily visits must include all working days (Saturday through Thursday).');
            }

            return;
        }

        $count = count($normalized);
        if ($count < self::MIN_VISITS_PER_WEEK || $count > self::MAX_VISITS_PER_WEEK) {
            throw new \InvalidArgumentException(
                'Select '.self::MIN_VISITS_PER_WEEK.' to '.self::MAX_VISITS_PER_WEEK.' visit days per week, or enable daily visits.'
            );
        }
    }

    /**
     * Promoter template days when assigning new clients (1–3 per week, not daily).
     *
     * @param  list<int|string>  $days
     */
    public static function validateDefaultVisitDays(array $days): void
    {
        $count = count(self::normalizeList($days));
        if ($count < self::MIN_VISITS_PER_WEEK || $count > self::MAX_VISITS_PER_WEEK) {
            throw new \InvalidArgumentException(
                'Select '.self::MIN_VISITS_PER_WEEK.' to '.self::MAX_VISITS_PER_WEEK.' typical visit days for this promoter.'
            );
        }
    }

    /**
     * @return list<int>
     */
    public static function parseCsv(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        return self::normalizeList($decoded);
    }

    /**
     * @param  list<int>  $days
     */
    public static function toJson(array $days): string
    {
        return json_encode(self::normalizeList($days), JSON_THROW_ON_ERROR);
    }

    public static function normalizeWeekStart(string $date): string
    {
        return Carbon::parse($date)->startOfWeek(self::WEEK_START_DAY)->startOfDay()->toDateString();
    }

    public static function weekEndDate(string $weekStart): string
    {
        return Carbon::parse(self::normalizeWeekStart($weekStart))
            ->addDays(self::WEEK_LENGTH_DAYS - 1)
            ->toDateString();
    }

    /**
     * Schedule columns for a promotions week (Saturday through Thursday), excluding Friday and configured holidays.
     *
     * @return list<array{weekday: int, date: string, label: string}>
     */
    public static function columnsForWeek(string $weekStart): array
    {
        $start = Carbon::parse(self::normalizeWeekStart($weekStart));
        $columns = [];

        for ($offset = 0; $offset < self::WEEK_LENGTH_DAYS; $offset++) {
            $date = $start->copy()->addDays($offset);
            $weekday = (int) $date->dayOfWeek;
            if ($weekday === self::FRIDAY) {
                continue;
            }
            if (! WorkingDays::isBusinessDay($date, excludeFridays: true, excludeConfiguredHolidays: true)) {
                continue;
            }

            $columns[] = [
                'weekday' => $weekday,
                'date' => $date->toDateString(),
                'label' => self::label($weekday),
            ];
        }

        return $columns;
    }
}
