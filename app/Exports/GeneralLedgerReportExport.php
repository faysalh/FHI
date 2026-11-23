<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\NumberDisplay;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class GeneralLedgerReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  list<object>  $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly float $totalDebit = 0.0,
        private readonly float $totalCredit = 0.0,
    ) {}

    public function collection(): Collection
    {
        $c = Collection::make($this->rows);
        $c->push((object) [
            'document_type' => '__TOTAL__',
            'debit' => $this->totalDebit,
            'credit' => $this->totalCredit,
            'balance' => $this->totalDebit - $this->totalCredit,
        ]);

        return $c;
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
            'Account code',
            'Account',
            'Type',
            'Invoice #',
            'Date',
            'Debit',
            'Credit',
            'Balance',
            'Cross account',
            'Branch',
            'Description',
            'Notes',
            'Ref 1',
            'Ref 2',
            'Payment type',
            'Currency',
            'Rate',
        ];
    }

    /**
     * @param  object  $row
     * @return list<string>
     */
    public function map($row): array
    {
        if ((string) ($row->document_type ?? '') === '__TOTAL__') {
            return [
                '',
                'Period total (excludes opening)',
                '',
                '',
                '',
                NumberDisplay::format((float) ($row->debit ?? 0)),
                NumberDisplay::format((float) ($row->credit ?? 0)),
                NumberDisplay::format((float) ($row->balance ?? 0)),
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ];
        }

        $date = $row->register_date ?? null;
        $dateStr = '';
        if ($date !== null && $date !== '') {
            try {
                $dateStr = \Illuminate\Support\Carbon::parse((string) $date)->format('Y-m-d');
            } catch (\Throwable) {
                $dateStr = (string) $date;
            }
        }

        return [
            (string) ($row->account_code ?? ''),
            (string) ($row->account_name ?? ''),
            (string) ($row->document_type ?? ''),
            (string) ($row->document_no ?? ''),
            $dateStr,
            NumberDisplay::format((float) ($row->debit ?? 0)),
            NumberDisplay::format((float) ($row->credit ?? 0)),
            NumberDisplay::format((float) ($row->balance ?? 0)),
            (string) ($row->cross_account ?? ''),
            (string) ($row->branch ?? ''),
            (string) ($row->description ?? ''),
            (string) ($row->notes ?? ''),
            (string) ($row->ref_no1 ?? ''),
            (string) ($row->ref_no2 ?? ''),
            (string) ($row->payment_type ?? ''),
            (string) ($row->currency_symbol ?? ''),
            NumberDisplay::format((float) ($row->currency_rate ?? 0)),
        ];
    }
}
