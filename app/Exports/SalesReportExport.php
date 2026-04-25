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
     * @param  'totals'|'by_client'|'by_category'|'by_category_by_client'  $mode
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly string $mode
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
        return match ($this->mode) {
            'totals' => ['Quantity (pcs)', 'Amount (IQD)', 'Weight (kg)'],
            'by_client' => ['Client code', 'Client name', 'Quantity (pcs)', 'Amount (IQD)', 'Weight (kg)'],
            'by_category' => ['Category (item description)', 'Quantity (pcs)', 'Amount (IQD)', 'Weight (kg)'],
            'by_category_by_client' => ['Client code', 'Client name', 'Category (item description)', 'Quantity (pcs)', 'Amount (IQD)', 'Weight (kg)'],
            default => [],
        };
    }

    /**
     * @param  stdClass  $row
     * @return list<string|float|int>
     */
    public function map($row): array
    {
        return match ($this->mode) {
            'totals' => [
                NumberDisplay::format($row->units_sold ?? null),
                NumberDisplay::format($row->amount ?? null),
                NumberDisplay::format($row->weight_total ?? null),
            ],
            'by_client' => [
                (string) ($row->client_code ?? ''),
                (string) ($row->client_name ?? ''),
                NumberDisplay::format($row->units_sold ?? null),
                NumberDisplay::format($row->amount ?? null),
                NumberDisplay::format($row->weight_total ?? null),
            ],
            'by_category' => [
                (string) ($row->chicken_category ?? ''),
                NumberDisplay::format($row->units_sold ?? null),
                NumberDisplay::format($row->amount ?? null),
                NumberDisplay::format($row->weight_total ?? null),
            ],
            'by_category_by_client' => [
                (string) ($row->client_code ?? ''),
                (string) ($row->client_name ?? ''),
                (string) ($row->chicken_category ?? ''),
                NumberDisplay::format($row->units_sold ?? null),
                NumberDisplay::format($row->amount ?? null),
                NumberDisplay::format($row->weight_total ?? null),
            ],
            default => [],
        };
    }
}
