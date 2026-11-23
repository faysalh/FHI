<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\NumberDisplay;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ClientBalanceReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  list<object>  $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly float $grandTotal = 0.0
    ) {}

    public function collection(): Collection
    {
        $c = Collection::make($this->rows);
        $c->push((object) [
            'client_code' => '',
            'client_name' => '__GRAND_TOTAL__',
            'salesman_name' => '',
            'balance' => $this->grandTotal,
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
            'Client code',
            'Client name',
            'Salesman',
            'Balance',
        ];
    }

    /**
     * @param  object  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $name = (string) ($row->client_name ?? '');
        if ($name === '__GRAND_TOTAL__') {
            return [
                '',
                'Total',
                '',
                NumberDisplay::format((float) ($row->balance ?? 0)),
            ];
        }

        return [
            (string) ($row->client_code ?? ''),
            $name,
            (string) ($row->salesman_name ?? ''),
            NumberDisplay::format((float) ($row->balance ?? 0)),
        ];
    }
}
