<?php

declare(strict_types=1);

namespace App\Support;

use stdClass;

final class VisitsReportRowValues
{
    public static function readMonthFlag(stdClass $row, string $sqlAlias): bool
    {
        foreach ([$sqlAlias, strtolower($sqlAlias)] as $key) {
            if (property_exists($row, $key)) {
                return (int) $row->{$key} === 1;
            }
        }

        return false;
    }

    public static function readSalesAmount(stdClass $row, string $salesAlias): float
    {
        foreach ([$salesAlias, strtolower($salesAlias)] as $key) {
            if (property_exists($row, $key)) {
                return (float) $row->{$key};
            }
        }

        return 0.0;
    }
}
