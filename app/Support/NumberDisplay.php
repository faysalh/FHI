<?php

declare(strict_types=1);

namespace App\Support;

/**
 * UI number formatting: omit ".00" when the value is a whole number; otherwise show
 * meaningful fractional digits and strip trailing zeros (e.g. 1,234.5 not 1,234.50).
 */
final class NumberDisplay
{
    private const WHOLE_EPSILON = 1e-9;

    public static function format(float|int|string|null $value, int $maxDecimals = 8): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (! is_numeric($value)) {
            return '0';
        }

        $n = (float) $value;
        if (! is_finite($n)) {
            return '0';
        }

        $maxDecimals = max(0, min(20, $maxDecimals));

        if (abs($n - round($n)) < self::WHOLE_EPSILON) {
            return number_format(round($n), 0, '.', ',');
        }

        $formatted = number_format($n, $maxDecimals, '.', ',');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted;
    }
}
