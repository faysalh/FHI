<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\NumberDisplay;
use App\Support\SalesItemPriceTiers;
use App\Support\SalesItemReportMetrics;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use stdClass;

class SalesByItemReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $rows
     * @param  list<array{tier: int, label: string}>  $priceTiers
     * @param  list<array{key: string, label: string, suffix: string}>  $activeMetrics
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly ?stdClass $grandTotals = null,
        private readonly array $priceTiers = [],
        private readonly bool $showUnknownColumn = true,
        private readonly array $activeMetrics = [],
    ) {}

    public function collection(): Collection
    {
        $c = Collection::make($this->rows);
        if ($this->grandTotals !== null) {
            $footer = clone $this->grandTotals;
            $footer->category_name = '__GRAND_TOTAL__';
            $c->push($footer);
        }

        return $c;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCsvSettings(): array
    {
        return [
            'use_bom' => true,
        ];
    }

    /**
     * @return list<array{tier: int, label: string}>
     */
    private function activeTiers(): array
    {
        return $this->priceTiers !== [] ? $this->priceTiers : SalesItemPriceTiers::definitions();
    }

    /**
     * @return list<array{key: string, label: string, suffix: string}>
     */
    private function metrics(): array
    {
        return $this->activeMetrics !== [] ? $this->activeMetrics : SalesItemReportMetrics::definitions();
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = ['Category'];
        foreach ($this->activeTiers() as $tier) {
            $label = $tier['label'];
            $n = $tier['tier'];
            foreach ($this->metrics() as $metric) {
                $headings[] = "Price {$n} ({$label}) {$metric['label']}";
            }
        }
        if ($this->showUnknownColumn) {
            foreach ($this->metrics() as $metric) {
                $headings[] = 'Unknown group '.$metric['label'];
            }
        }
        foreach ($this->metrics() as $metric) {
            $headings[] = 'Total '.$metric['label'];
        }

        return $headings;
    }

    /**
     * @param  stdClass  $row
     * @return list<string|float|int>
     */
    public function map($row): array
    {
        $isTotal = ($row->category_name ?? '') === '__GRAND_TOTAL__';
        $out = [$isTotal ? 'Total' : (string) ($row->category_name ?? '')];

        foreach ($this->activeTiers() as $tier) {
            foreach ($this->metrics() as $metric) {
                $field = SalesItemReportMetrics::fieldKey('tier', $metric['suffix'], $tier['tier']);
                $out[] = NumberDisplay::format($row->{$field} ?? null);
            }
        }

        if ($this->showUnknownColumn) {
            foreach ($this->metrics() as $metric) {
                $field = SalesItemReportMetrics::fieldKey('unmatched', $metric['suffix']);
                $out[] = NumberDisplay::format($row->{$field} ?? null);
            }
        }

        foreach ($this->metrics() as $metric) {
            $field = SalesItemReportMetrics::fieldKey('total', $metric['suffix']);
            $out[] = NumberDisplay::format($row->{$field} ?? null);
        }

        return $out;
    }
}
