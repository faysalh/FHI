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

class InvoicesReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
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
            'Invoice no',
            'Date',
            'Last print date',
            'Client code',
            'Client name',
            'City',
            'Salesman',
            'Store',
            'Quantity (pcs)',
            'Invoice amount (IQD)',
            'Client due (IQD)',
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
            (string) ($row->invoice_no ?? ''),
            (string) ($row->invoice_date ?? ''),
            (string) ($row->last_print_date ?? ''),
            (string) ($row->client_code ?? ''),
            (string) ($row->client_name ?? ''),
            (string) ($row->city_name ?? ''),
            (string) ($row->salesman_name ?? ''),
            (string) ($row->store_name ?? ''),
            NumberDisplay::format((float) ($row->quantity_total ?? 0)),
            NumberDisplay::format((float) ($row->invoice_amount ?? 0)),
            NumberDisplay::format((float) ($row->client_due_amount ?? 0)),
        ];
    }
}
