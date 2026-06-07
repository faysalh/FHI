<?php

declare(strict_types=1);

namespace App\Support;

/**
 * UI number formatting: whole numbers only, thousands separators (e.g. 1,234).
 */
final class NumberDisplay
{
    public static function format(float|int|string|null $value, int $maxDecimals = 0): string
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

        return number_format((int) round($n), 0, '.', ',');
    }
}
