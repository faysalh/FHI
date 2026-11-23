<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

final class ReportingTime
{
    public static function timezone(): string
    {
        $tz = config('reporting.timezone') ?? config('app.timezone') ?? 'Asia/Baghdad';
        if (! is_string($tz) || $tz === '') {
            $tz = 'Asia/Baghdad';
        }

        // Legacy installs cached UTC before APP_TIMEZONE existed (Iraq deployments).
        if ($tz === 'UTC') {
            return 'Asia/Baghdad';
        }

        return $tz;
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    public static function formatStored(?string $stored, string $format = 'Y-m-d H:i:s'): string
    {
        if ($stored === null || trim($stored) === '') {
            return '';
        }

        return Carbon::parse($stored, self::timezone())->format($format);
    }
}
