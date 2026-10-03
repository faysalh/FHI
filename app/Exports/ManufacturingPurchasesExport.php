<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\NumberDisplay;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ManufacturingPurchasesExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  list<object>  $rows
     */
    public function __construct(private readonly array $rows) {}

    public function collection(): Collection
    {
        return Collection::make($this->rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCsvSettings(): array
    {
        return ['use_bom' => true];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Date',
            'Item',
            'Unit',
            'Quantity',
            'Cost',
            'Currency',
            'Supplier',
            'Note',
        ];
    }

    /**
     * @param  object  $row
     * @return list<string|int|float>
     */
    public function map($row): array
    {
        return [
            (string) ($row->purchase_date ?? ''),
            (string) ($row->item_name ?? ''),
            (string) ($row->item_unit ?? ''),
            NumberDisplay::format((float) ($row->quantity ?? 0)),
            NumberDisplay::format((float) ($row->cost_amount ?? 0)),
            strtoupper((string) ($row->currency ?? 'IQD')),
            (string) ($row->supplier_name ?? ''),
            (string) ($row->note ?? ''),
        ];
    }
}
