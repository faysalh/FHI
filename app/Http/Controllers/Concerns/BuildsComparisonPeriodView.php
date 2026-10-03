<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

trait BuildsComparisonPeriodView
{
    /**
     * @param  list<object>  $period1Rows
     * @param  list<object>  $period2Rows
     * @return list<object>
     */
    protected function mergePeriodRows(array $period1Rows, array $period2Rows): array
    {
        $merged = [];
        foreach ($period1Rows as $row) {
            $category = trim((string) ($row->category_name ?? ''));
            $item = trim((string) ($row->item_name ?? ''));
            $key = mb_strtolower($category).'|'.mb_strtolower($item);
            $merged[$key] = (object) [
                'category_name' => $category,
                'item_name' => $item,
                'period1_quantity' => (float) ($row->quantity_total ?? 0),
                'period1_amount' => (float) ($row->amount_total ?? 0),
                'period1_weight' => (float) ($row->weight_total ?? 0),
                'period2_quantity' => 0.0,
                'period2_amount' => 0.0,
                'period2_weight' => 0.0,
            ];
        }
        foreach ($period2Rows as $row) {
            $category = trim((string) ($row->category_name ?? ''));
            $item = trim((string) ($row->item_name ?? ''));
            $key = mb_strtolower($category).'|'.mb_strtolower($item);
            if (! isset($merged[$key])) {
                $merged[$key] = (object) [
                    'category_name' => $category,
                    'item_name' => $item,
                    'period1_quantity' => 0.0,
                    'period1_amount' => 0.0,
                    'period1_weight' => 0.0,
                    'period2_quantity' => 0.0,
                    'period2_amount' => 0.0,
                    'period2_weight' => 0.0,
                ];
            }
            $merged[$key]->period2_quantity = (float) ($row->quantity_total ?? 0);
            $merged[$key]->period2_amount = (float) ($row->amount_total ?? 0);
            $merged[$key]->period2_weight = (float) ($row->weight_total ?? 0);
        }

        foreach ($merged as $key => $row) {
            $row->diff_quantity = (float) $row->period2_quantity - (float) $row->period1_quantity;
            $row->diff_amount = (float) $row->period2_amount - (float) $row->period1_amount;
            $row->diff_weight = (float) $row->period2_weight - (float) $row->period1_weight;
            $merged[$key] = $row;
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $viewData
     * @return array{0:list<string>,1:array<int,array<int,string|int|float>>}
     */
    protected function buildExportRows(array $viewData): array
    {
        $metrics = is_array($viewData['filters']['metrics'] ?? null) ? $viewData['filters']['metrics'] : ['quantity', 'amount', 'weight'];
        $headings = ['Category', 'Item'];
        foreach ($metrics as $metric) {
            $headings[] = 'P1 '.$this->metricLabel((string) $metric);
        }
        foreach ($metrics as $metric) {
            $headings[] = 'P2 '.$this->metricLabel((string) $metric);
        }
        foreach ($metrics as $metric) {
            $headings[] = 'Diff '.$this->metricLabel((string) $metric);
        }

        $rows = [];
        $groups = is_array($viewData['groupedRows'] ?? null) ? $viewData['groupedRows'] : [];
        foreach ($groups as $group) {
            $category = (string) ($group['category'] ?? '');
            $groupRows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
            foreach ($groupRows as $row) {
                $line = [
                    (string) ($row->category_name ?? ''),
                    (string) ($row->item_name ?? ''),
                ];
                foreach ($metrics as $metric) {
                    $line[] = $this->formatMetricValue((string) $metric, (float) ($row->{'period1_'.$metric} ?? 0));
                }
                foreach ($metrics as $metric) {
                    $line[] = $this->formatMetricValue((string) $metric, (float) ($row->{'period2_'.$metric} ?? 0));
                }
                foreach ($metrics as $metric) {
                    $line[] = $this->formatMetricValue((string) $metric, (float) ($row->{'diff_'.$metric} ?? 0));
                }
                $rows[] = $line;
            }

            $groupTotals = is_array($group['totals'] ?? null) ? $group['totals'] : [];
            $subtotalLine = ['Subtotal: '.$category, ''];
            foreach ($metrics as $metric) {
                $subtotalLine[] = $this->formatMetricValue((string) $metric, (float) ($groupTotals['period1_'.$metric] ?? 0));
            }
            foreach ($metrics as $metric) {
                $subtotalLine[] = $this->formatMetricValue((string) $metric, (float) ($groupTotals['period2_'.$metric] ?? 0));
            }
            foreach ($metrics as $metric) {
                $subtotalLine[] = $this->formatMetricValue((string) $metric, (float) ($groupTotals['diff_'.$metric] ?? 0));
            }
            $rows[] = $subtotalLine;
            $rows[] = $this->buildGrowthExportLine('Growth %: '.$category, $metrics, $groupTotals);
        }

        $totals = is_array($viewData['totals'] ?? null) ? $viewData['totals'] : [];
        if ($totals !== []) {
            $totalLine = ['TOTAL', ''];
            foreach ($metrics as $metric) {
                $totalLine[] = $this->formatMetricValue((string) $metric, (float) ($totals['period1_'.$metric] ?? 0));
            }
            foreach ($metrics as $metric) {
                $totalLine[] = $this->formatMetricValue((string) $metric, (float) ($totals['period2_'.$metric] ?? 0));
            }
            foreach ($metrics as $metric) {
                $totalLine[] = $this->formatMetricValue((string) $metric, (float) ($totals['diff_'.$metric] ?? 0));
            }
            $rows[] = $totalLine;
            $rows[] = $this->buildGrowthExportLine('Growth %', $metrics, $totals);
        }

        return [$headings, $rows];
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, float>
     */
    protected function calculateTotals(array $rows): array
    {
        $totals = [
            'period1_quantity' => 0.0,
            'period1_amount' => 0.0,
            'period1_weight' => 0.0,
            'period2_quantity' => 0.0,
            'period2_amount' => 0.0,
            'period2_weight' => 0.0,
            'diff_quantity' => 0.0,
            'diff_amount' => 0.0,
            'diff_weight' => 0.0,
        ];

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $column) {
                $totals[$column] += (float) ($row->{$column} ?? 0);
            }
        }

        return $totals;
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{category:string,rows:list<object>,totals:array<string,float|null>}>
     */
    protected function groupRowsByCategoryWithGrowth(array $rows): array
    {
        /** @var array<string, array{category:string,rows:list<object>,totals:array<string,float>}> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $category = trim((string) ($row->category_name ?? ''));
            $key = mb_strtolower($category);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'category' => $category,
                    'rows' => [],
                    'totals' => [
                        'period1_quantity' => 0.0,
                        'period1_amount' => 0.0,
                        'period1_weight' => 0.0,
                        'period2_quantity' => 0.0,
                        'period2_amount' => 0.0,
                        'period2_weight' => 0.0,
                        'diff_quantity' => 0.0,
                        'diff_amount' => 0.0,
                        'diff_weight' => 0.0,
                    ],
                ];
            }

            $groups[$key]['rows'][] = $row;
            foreach (array_keys($groups[$key]['totals']) as $column) {
                if (! str_starts_with($column, 'growth_')) {
                    $groups[$key]['totals'][$column] += (float) ($row->{$column} ?? 0);
                }
            }
        }

        return array_map(function (array $group): array {
            $group['totals'] = $this->enrichTotalsWithGrowth($group['totals']);

            return $group;
        }, array_values($groups));
    }

    /**
     * @param  array<string, float>  $totals
     * @return array<string, float|null>
     */
    protected function enrichTotalsWithGrowth(array $totals): array
    {
        foreach (['quantity', 'amount', 'weight'] as $metric) {
            $period1 = (float) ($totals['period1_'.$metric] ?? 0);
            $period2 = (float) ($totals['period2_'.$metric] ?? 0);
            $totals['growth_'.$metric] = $this->growthPercent($period1, $period2);
        }

        return $totals;
    }

    protected function growthPercent(float $period1, float $period2): ?float
    {
        if ($period1 == 0.0) {
            return null;
        }

        return (($period2 - $period1) / $period1) * 100.0;
    }

    protected function formatGrowthPercent(?float $growth): string
    {
        if ($growth === null) {
            return '—';
        }

        $formatted = \App\Support\NumberDisplay::format($growth);

        return ($growth > 0.0 ? '+' : '').$formatted.'%';
    }

    /**
     * @param  list<string>  $metrics
     * @param  array<string, float|null>  $totals
     * @return list<string>
     */
    protected function buildGrowthExportLine(string $label, array $metrics, array $totals): array
    {
        $line = [$label, ''];
        foreach ($metrics as $metric) {
            $line[] = '';
        }
        foreach ($metrics as $metric) {
            $line[] = '';
        }
        foreach ($metrics as $metric) {
            $growth = $totals['growth_'.$metric] ?? null;
            $line[] = is_float($growth) ? $this->formatGrowthPercent($growth) : '—';
        }

        return $line;
    }

    protected function metricLabel(string $metric): string
    {
        return match ($metric) {
            'quantity' => 'Quantity (carton)',
            'amount' => 'Amount (IQD)',
            'weight' => 'Weight (kg)',
            default => ucfirst($metric),
        };
    }

    protected function formatMetricValue(string $metric, float $value): string
    {
        if ($metric === 'amount') {
            return 'IQD '.\App\Support\NumberDisplay::format($value);
        }

        return \App\Support\NumberDisplay::format($value);
    }
}
