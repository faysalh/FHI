<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use stdClass;

class MarketStudyExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    private int $rowNumber = 0;

    /**
     * @param  list<stdClass>  $rows
     */
    public function __construct(
        private readonly array $rows
    ) {}

    public function collection(): Collection
    {
        return Collection::make($this->rows);
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
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            '#',
            'Logged on',
            'Market',
            'Item',
            'Brand',
            'Price / KG',
            'Notes',
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $price = isset($row->price_per_kg) ? (float) $row->price_per_kg : 0.0;

        return [
            (string) (++$this->rowNumber),
            (string) ($row->logged_on ?? ''),
            (string) ($row->market_name ?? ''),
            (string) ($row->item_name ?? ''),
            (string) ($row->brand ?? ''),
            display_number($price, 4),
            (string) ($row->notes ?? ''),
        ];
    }
}
