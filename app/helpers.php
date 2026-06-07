<?php

declare(strict_types=1);

use App\Support\NumberDisplay;

if (! function_exists('display_number')) {
    /**
     * Format a numeric value for display as a whole number (no decimal places).
     */
    function display_number(float|int|string|null $value, int $maxDecimals = 0): string
    {
        return NumberDisplay::format($value, $maxDecimals);
    }
}
