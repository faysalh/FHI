<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\NumberDisplay;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ManufacturingExportsExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
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
            (string) ($row->export_date ?? ''),
            (string) ($row->item_name ?? ''),
            (string) ($row->item_unit ?? ''),
            NumberDisplay::format((float) ($row->quantity ?? 0)),
            (string) ($row->note ?? ''),
        ];
    }
}
