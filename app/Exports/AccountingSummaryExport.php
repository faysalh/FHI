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

class AccountingSummaryExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  list<stdClass>  $cashSummary
     * @param  list<stdClass>  $transferSummary
     */
    public function __construct(
        private readonly array $cashSummary,
        private readonly array $transferSummary
    ) {}

    public function collection(): Collection
    {
        $rows = [];

        foreach ($this->cashSummary as $row) {
            $rows[] = (object) [
                'section' => 'Cash',
                'date' => (string) ($row->sheet_date ?? ''),
                'opening_iqd' => (float) ($row->opening_amount ?? 0),
                'spent_iqd' => (float) ($row->spent_total ?? 0),
                'remaining_iqd' => (float) ($row->remaining_total ?? 0),
                'transfer_rows' => '',
                'transfer_iqd' => '',
                'usd_rows' => '',
                'usd_amount' => '',
            ];
        }

        foreach ($this->transferSummary as $row) {
            $rows[] = (object) [
                'section' => 'Transfers',
                'date' => (string) ($row->transfer_date ?? ''),
                'opening_iqd' => '',
                'spent_iqd' => '',
                'remaining_iqd' => '',
                'transfer_rows' => (int) ($row->row_count ?? 0),
                'transfer_iqd' => (float) ($row->iqd_total ?? 0),
                'usd_rows' => (int) ($row->usd_row_count ?? 0),
                'usd_amount' => (float) ($row->usd_amount_total ?? 0),
            ];
        }

        return Collection::make($rows);
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
            'Section',
            'Date',
            'Opening IQD',
            'Spent IQD',
            'Remaining IQD',
            'Transfer rows',
            'Transfer IQD equivalent',
            'USD rows',
            'USD amount total',
        ];
    }

    /**
     * @param  object  $row
     * @return list<string|int|float>
     */
    public function map($row): array
    {
        return [
            (string) ($row->section ?? ''),
            (string) ($row->date ?? ''),
            $row->opening_iqd === '' ? '' : NumberDisplay::format((float) $row->opening_iqd),
            $row->spent_iqd === '' ? '' : NumberDisplay::format((float) $row->spent_iqd),
            $row->remaining_iqd === '' ? '' : NumberDisplay::format((float) $row->remaining_iqd),
            $row->transfer_rows === '' ? '' : (string) $row->transfer_rows,
            $row->transfer_iqd === '' ? '' : NumberDisplay::format((float) $row->transfer_iqd),
            $row->usd_rows === '' ? '' : (string) $row->usd_rows,
            $row->usd_amount === '' ? '' : NumberDisplay::format((float) $row->usd_amount),
        ];
    }
}
