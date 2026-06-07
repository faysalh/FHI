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

class DamagesReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
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
            'Date',
            'Client',
            'Salesman',
            'Item',
            'Damaged pieces',
            'Pieces per carton',
            'Carton price',
            'Amount',
            'Notes',
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        return [
            (string) (++$this->rowNumber),
            (string) ($row->occurred_date ?? ''),
            (string) ($row->client_name_snapshot ?? ''),
            (string) ($row->salesman_name_snapshot ?? ''),
            (string) ($row->item_name_snapshot ?? ''),
            NumberDisplay::format((int) ($row->damaged_pieces ?? 0)),
            NumberDisplay::format((int) ($row->pieces_per_main_unit ?? 1)),
            NumberDisplay::format((float) ($row->carton_price ?? 0)),
            NumberDisplay::format((float) ($row->amount_total ?? 0)),
            (string) ($row->notes ?? ''),
        ];
    }
}
