<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds the Asan comparison table from one row set per calendar month.
 */
final class ComparisonMonthRows
{
    /**
     * @param  list<array{key: string, label: string}>  $slices
     * @param  array<string, list<object>>  $rowsByKey
     * @return list<object>
     */
    public static function merge(array $slices, array $rowsByKey): array
    {
        $merged = [];
        foreach ($slices as $slice) {
            $sliceKey = $slice['key'];
            foreach ($rowsByKey[$sliceKey] ?? [] as $row) {
                $category = trim((string) ($row->category_name ?? ''));
                $item = trim((string) ($row->item_name ?? ''));
                $id = mb_strtolower($category).'|'.mb_strtolower($item);
                if (! isset($merged[$id])) {
                    $merged[$id] = (object) [
                        'category_name' => $category,
                        'item_name' => $item,
                        'months' => self::blankMonths($slices),
                        'diff_quantity' => 0.0,
                        'diff_amount' => 0.0,
                        'diff_weight' => 0.0,
                    ];
                }
                $merged[$id]->months[$sliceKey] = [
                    'quantity' => (float) ($row->quantity_total ?? 0),
                    'amount' => (float) ($row->amount_total ?? 0),
                    'weight' => (float) ($row->weight_total ?? 0),
                ];
            }
        }

        [$prior, $latest] = self::changeKeys($slices);
        foreach ($merged as $row) {
            if ($prior === null || $latest === null) {
                continue;
            }
            foreach (self::metrics() as $metric) {
                $row->{'diff_'.$metric} = (float) $row->months[$latest][$metric] - (float) $row->months[$prior][$metric];
            }
        }

        return array_values($merged);
    }

    /**
     * @param  list<object>  $rows
     * @param  list<array{key: string}>  $slices
     * @return array<string, mixed>
     */
    public static function totals(array $rows, array $slices): array
    {
        $totals = [
            'months' => self::blankMonths($slices),
            'diff_quantity' => 0.0,
            'diff_amount' => 0.0,
            'diff_weight' => 0.0,
        ];
        foreach ($rows as $row) {
            foreach ($slices as $slice) {
                foreach (self::metrics() as $metric) {
                    $totals['months'][$slice['key']][$metric] += (float) ($row->months[$slice['key']][$metric] ?? 0);
                }
            }
            foreach (self::metrics() as $metric) {
                $totals['diff_'.$metric] += (float) ($row->{'diff_'.$metric} ?? 0);
            }
        }

        return self::withGrowth($totals, $slices);
    }

    /**
     * @param  list<object>  $rows
     * @param  list<array{key: string}>  $slices
     * @return list<array{category: string, rows: list<object>, totals: array<string, mixed>}>
     */
    public static function grouped(array $rows, array $slices): array
    {
        /** @var array<string, array{category: string, rows: list<object>, totals: array<string, mixed>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $category = trim((string) ($row->category_name ?? ''));
            $key = mb_strtolower($category);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'category' => $category,
                    'rows' => [],
                    'totals' => [
                        'months' => self::blankMonths($slices),
                        'diff_quantity' => 0.0,
                        'diff_amount' => 0.0,
                        'diff_weight' => 0.0,
                    ],
                ];
            }
            $groups[$key]['rows'][] = $row;
            foreach ($slices as $slice) {
                foreach (self::metrics() as $metric) {
                    $groups[$key]['totals']['months'][$slice['key']][$metric] += (float) ($row->months[$slice['key']][$metric] ?? 0);
                }
            }
            foreach (self::metrics() as $metric) {
                $groups[$key]['totals']['diff_'.$metric] += (float) ($row->{'diff_'.$metric} ?? 0);
            }
        }

        return array_map(function (array $group) use ($slices): array {
            $group['totals'] = self::withGrowth($group['totals'], $slices);

            return $group;
        }, array_values($groups));
    }

    /**
     * @param  list<array{key: string, label: string}>  $slices
     * @param  list<array{category: string, rows: list<object>, totals: array<string, mixed>}>  $groups
     * @param  array<string, mixed>  $totals
     * @param  list<string>  $metrics
     * @return array{0: list<string>, 1: array<int, list<string>>}
     */
    public static function export(array $slices, array $groups, array $totals, array $metrics): array
    {
        $showChange = count($slices) >= 2;
        $changeLabel = ComparisonMonthSpan::changeLabel($slices) ?? 'Change';
        $headings = ['Category', 'Item'];
        foreach ($slices as $slice) {
            foreach ($metrics as $metric) {
                $headings[] = $slice['label'].' '.self::metricLabel($metric);
            }
        }
        if ($showChange) {
            foreach ($metrics as $metric) {
                $headings[] = $changeLabel.' '.self::metricLabel($metric);
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $rows[] = self::exportLine(
                    (string) ($row->category_name ?? ''),
                    (string) ($row->item_name ?? ''),
                    $slices,
                    $metrics,
                    $row->months,
                    $showChange,
                    $row
                );
            }
            $rows[] = self::exportLine(
                'Subtotal: '.$group['category'],
                '',
                $slices,
                $metrics,
                $group['totals']['months'] ?? [],
                $showChange,
                $group['totals']
            );
            if ($showChange) {
                $rows[] = self::growthLine('Growth %: '.$group['category'], $slices, $metrics, $group['totals']);
            }
        }
        if ($groups !== []) {
            $rows[] = self::exportLine(
                'TOTAL',
                '',
                $slices,
                $metrics,
                $totals['months'] ?? [],
                $showChange,
                $totals
            );
            if ($showChange) {
                $rows[] = self::growthLine('Growth %', $slices, $metrics, $totals);
            }
        }

        return [$headings, $rows];
    }

    /**
     * @param  list<array{key: string}>  $slices
     * @return array<string, array{quantity: float, amount: float, weight: float}>
     */
    private static function blankMonths(array $slices): array
    {
        $months = [];
        foreach ($slices as $slice) {
            $months[$slice['key']] = [
                'quantity' => 0.0,
                'amount' => 0.0,
                'weight' => 0.0,
            ];
        }

        return $months;
    }

    /**
     * @param  list<array{key: string}>  $slices
     * @return array{0: ?string, 1: ?string}
     */
    private static function changeKeys(array $slices): array
    {
        if (count($slices) < 2) {
            return [null, null];
        }

        return [
            $slices[count($slices) - 2]['key'],
            $slices[count($slices) - 1]['key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $totals
     * @param  list<array{key: string}>  $slices
     * @return array<string, mixed>
     */
    private static function withGrowth(array $totals, array $slices): array
    {
        [$prior, $latest] = self::changeKeys($slices);
        foreach (self::metrics() as $metric) {
            $totals['growth_'.$metric] = null;
            if ($prior === null || $latest === null) {
                continue;
            }
            $before = (float) ($totals['months'][$prior][$metric] ?? 0);
            $after = (float) ($totals['months'][$latest][$metric] ?? 0);
            $totals['growth_'.$metric] = $before == 0.0 ? null : (($after - $before) / $before) * 100.0;
        }

        return $totals;
    }

    /**
     * @param  list<array{key: string}>  $slices
     * @param  list<string>  $metrics
     * @param  array<string, array<string, float>>  $months
     * @return list<string>
     */
    private static function exportLine(
        string $label,
        string $item,
        array $slices,
        array $metrics,
        array $months,
        bool $showChange,
        object|array $diffSource
    ): array {
        $line = [$label, $item];
        foreach ($slices as $slice) {
            foreach ($metrics as $metric) {
                $line[] = self::formatMetric($metric, (float) ($months[$slice['key']][$metric] ?? 0));
            }
        }
        if ($showChange) {
            foreach ($metrics as $metric) {
                $value = is_array($diffSource)
                    ? (float) ($diffSource['diff_'.$metric] ?? 0)
                    : (float) ($diffSource->{'diff_'.$metric} ?? 0);
                $line[] = self::formatMetric($metric, $value);
            }
        }

        return $line;
    }

    /**
     * @param  list<array{key: string}>  $slices
     * @param  list<string>  $metrics
     * @param  array<string, mixed>  $totals
     * @return list<string>
     */
    private static function growthLine(string $label, array $slices, array $metrics, array $totals): array
    {
        $line = [$label, ''];
        foreach ($slices as $slice) {
            foreach ($metrics as $metric) {
                unset($slice);
                $line[] = '';
            }
        }
        foreach ($metrics as $metric) {
            $growth = $totals['growth_'.$metric] ?? null;
            $line[] = is_float($growth) ? self::formatGrowth($growth) : '—';
        }

        return $line;
    }

    /**
     * @return list<string>
     */
    private static function metrics(): array
    {
        return ['quantity', 'amount', 'weight'];
    }

    private static function metricLabel(string $metric): string
    {
        return match ($metric) {
            'quantity' => 'Quantity (carton)',
            'amount' => 'Amount (IQD)',
            'weight' => 'Weight (kg)',
            default => ucfirst($metric),
        };
    }

    private static function formatMetric(string $metric, float $value): string
    {
        if ($metric === 'amount') {
            return 'IQD '.NumberDisplay::format($value);
        }

        return NumberDisplay::format($value);
    }

    private static function formatGrowth(float $growth): string
    {
        return ($growth > 0.0 ? '+' : '').NumberDisplay::format($growth).'%';
    }
}
