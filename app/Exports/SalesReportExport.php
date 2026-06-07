<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\NumberDisplay;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use stdClass;

class SalesReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $rows
     * @param  'totals'|'by_client'|'by_category'|'by_category_items'|'by_category_by_client'  $mode
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly string $mode,
        private readonly bool $includeQuantity = true,
        private readonly bool $includeAmount = true,
        private readonly bool $includeWeight = true,
    ) {
    }

    public function collection(): Collection
    {
        return Collection::make($this->rows);
    }

    /**
     * UTF-8 BOM so Excel on Windows opens Arabic text in columns correctly.
     *
     * @return array<string, mixed>
     */
    public function getCsvSettings(): array
    {
        return [
            'use_bom' => true,
        ];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $metrics = $this->metricHeadings();

        return match ($this->mode) {
            'totals' => $metrics,
            'by_client' => array_merge(['Client code', 'Client name'], $metrics),
            'by_category' => array_merge(['Category (item description)'], $metrics),
            'by_category_items' => array_merge(['Category (item description)', 'Item name'], $metrics),
            'by_category_by_client' => array_merge(['Client code', 'Client name', 'Category (item description)'], $metrics),
            default => [],
        };
    }

    /**
     * @param  stdClass  $row
     * @return list<string|float|int>
     */
    public function map($row): array
    {
        $metrics = $this->metricValues($row);

        return match ($this->mode) {
            'totals' => $metrics,
            'by_client' => array_merge([
                (string) ($row->client_code ?? ''),
                (string) ($row->client_name ?? ''),
            ], $metrics),
            'by_category' => array_merge([
                (string) ($row->chicken_category ?? ''),
            ], $metrics),
            'by_category_items' => array_merge([
                (string) ($row->chicken_category ?? ''),
                (string) ($row->item_name ?? ''),
            ], $metrics),
            'by_category_by_client' => array_merge([
                (string) ($row->client_code ?? ''),
                (string) ($row->client_name ?? ''),
                (string) ($row->chicken_category ?? ''),
            ], $metrics),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function metricHeadings(): array
    {
        $headings = [];
        if ($this->includeQuantity) {
            $headings[] = 'Quantity (pcs)';
        }
        if ($this->includeAmount) {
            $headings[] = 'Amount (IQD)';
        }
        if ($this->includeWeight) {
            $headings[] = 'Weight (kg)';
        }

        return $headings;
    }

    /**
     * @return list<string>
     */
    private function metricValues(stdClass $row): array
    {
        $values = [];
        if ($this->includeQuantity) {
            $values[] = NumberDisplay::format($row->units_sold ?? null);
        }
        if ($this->includeAmount) {
            $values[] = NumberDisplay::format($row->amount ?? null);
        }
        if ($this->includeWeight) {
            $values[] = NumberDisplay::format($row->weight_total ?? null);
        }

        return $values;
    }
}
