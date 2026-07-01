<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Resolves the dashboard "as of" anchor date with safe fallbacks.
 *
 * Disable historical dates via config or ?live=1 to restore always-today behavior.
 */
final class DashboardLabAsOf
{
    public static function historicalDatesEnabled(): bool
    {
        return (bool) config('reporting.dashboard_lab.historical_dates_enabled', true);
    }

    public static function resolve(?string $dateInput, bool $forceLive = false): CarbonInterface
    {
        if ($forceLive || ! self::historicalDatesEnabled()) {
            return Carbon::now();
        }

        $raw = trim((string) ($dateInput ?? ''));
        if ($raw === '') {
            return Carbon::now();
        }

        try {
            $parsed = Carbon::parse($raw)->startOfDay();
            if ($parsed->isFuture()) {
                return Carbon::now();
            }

            return $parsed;
        } catch (Throwable) {
            return Carbon::now();
        }
    }

    public static function isLive(CarbonInterface $asOf): bool
    {
        return $asOf->toDateString() === Carbon::now()->toDateString();
    }

    public static function daySectionLabel(CarbonInterface $asOf): string
    {
        return self::isLive($asOf) ? 'Today' : $asOf->format('j M Y');
    }
}
