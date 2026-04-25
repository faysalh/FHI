<?php

declare(strict_types=1);

use App\Support\NumberDisplay;

if (! function_exists('display_number')) {
    /**
     * Format a numeric value for display: no unnecessary decimals; keep fractional part when non-zero.
     */
    function display_number(float|int|string|null $value, int $maxDecimals = 8): string
    {
        return NumberDisplay::format($value, $maxDecimals);
    }
}
