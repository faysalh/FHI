<?php

declare(strict_types=1);

namespace App\Support;

final class SalesItemReportMetrics
{
    public const QTY = 'qty';

    public const AMT = 'amt';

    public const WT = 'wt';

    /** @var list<string> */
    public const ALL = [self::QTY, self::AMT, self::WT];

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::QTY => 'Qty',
        self::AMT => 'Amount',
        self::WT => 'Weight',
    ];

    /**
     * @return list<array{key: string, label: string, suffix: string}>
     */
    public static function definitions(): array
    {
        $out = [];
        foreach (self::ALL as $key) {
            $out[] = [
                'key' => $key,
                'label' => self::LABELS[$key],
                'suffix' => $key,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>|null  $metrics
     * @return list<string>
     */
    public static function normalize(?array $metrics): array
    {
        if ($metrics === null || $metrics === []) {
            return [];
        }

        $out = [];
        foreach ($metrics as $metric) {
            if (! is_string($metric)) {
                continue;
            }
            $metric = strtolower(trim($metric));
            if (in_array($metric, self::ALL, true)) {
                $out[] = $metric;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $selected  Normalized keys; empty means all metrics.
     * @return list<array{key: string, label: string, suffix: string}>
     */
    public static function activeDefinitions(array $selected): array
    {
        if ($selected === []) {
            return self::definitions();
        }

        $set = array_fill_keys($selected, true);
        $out = [];
        foreach (self::definitions() as $def) {
            if (isset($set[$def['key']])) {
                $out[] = $def;
            }
        }

        return $out;
    }

    /**
     * @param  'tier'|'unmatched'|'total'  $group
     */
    public static function fieldKey(string $group, string $suffix, ?int $tier = null): string
    {
        if ($group === 'tier' && $tier !== null) {
            return 'p'.$tier.'_'.$suffix;
        }

        if ($group === 'unmatched') {
            return 'unmatched_'.$suffix;
        }

        return 'total_'.$suffix;
    }
}
