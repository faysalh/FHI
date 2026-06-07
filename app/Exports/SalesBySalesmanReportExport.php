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

class SalesBySalesmanReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $rows
     * @param  stdClass|null  $grandTotals  sums (sum_invoice_count, sum_quantity_sold, sum_amount) for footer row
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly ?stdClass $grandTotals = null,
    ) {}

    public function collection(): Collection
    {
        $c = Collection::make($this->rows);
        if ($this->grandTotals !== null) {
            $c->push((object) [
                'client_code' => '',
                'client_name' => '__GRAND_TOTAL__',
                'client_price_group' => '',
                'invoice_count' => $this->grandTotals->sum_invoice_count ?? 0,
                'quantity_sold' => $this->grandTotals->sum_quantity_sold ?? 0,
                'amount' => $this->grandTotals->sum_amount ?? 0,
            ]);
        }

        return $c;
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
            'Client code',
            'Client name',
            'Client price group',
            'Number of invoices',
            'Quantity of sales',
            'Amount of sales (IQD)',
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string|float|int>
     */
    public function map($row): array
    {
        if (($row->client_name ?? '') === '__GRAND_TOTAL__') {
            return [
                '',
                'Total',
                '',
                NumberDisplay::format($row->invoice_count ?? null),
                NumberDisplay::format($row->quantity_sold ?? null),
                NumberDisplay::format($row->amount ?? null),
            ];
        }

        return [
            (string) ($row->client_code ?? ''),
            (string) ($row->client_name ?? ''),
            (string) ($row->client_price_group ?? ''),
            NumberDisplay::format($row->invoice_count ?? null),
            NumberDisplay::format($row->quantity_sold ?? null),
            NumberDisplay::format($row->amount ?? null),
        ];
    }
}
