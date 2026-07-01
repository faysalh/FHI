<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\NumberDisplay;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use stdClass;

class RankingsReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  list<stdClass>  $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly string $tab,
        private readonly bool $growthTab,
    ) {}

    public function collection(): \Illuminate\Support\Collection
    {
        $out = [];
        foreach ($this->rows as $index => $row) {
            $row->export_rank = $index + 1;
            $out[] = $row;
        }

        return collect($out);
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
        if ($this->growthTab) {
            return [
                'Rank',
                'Client code',
                'Client name',
                'Current amount (IQD)',
                'Prior amount (IQD)',
                'Growth %',
                'Current quantity',
                'Current weight',
                'Invoices',
                'Share of period sales %',
            ];
        }

        $nameHeading = match ($this->tab) {
            'items' => 'Item',
            'salesmen' => 'Salesman',
            'categories' => 'Category',
            'cities' => 'City',
            default => 'Client name',
        };

        $headings = [
            'Rank',
            $nameHeading,
        ];

        if ($this->tab === 'clients') {
            $headings[] = 'Client code';
        }
        if ($this->tab === 'items') {
            $headings[] = 'Category';
        }

        return array_merge($headings, [
            'Amount (IQD)',
            'Quantity',
            'Weight',
            'Invoices',
            'Share of period sales %',
        ]);
    }

    /**
     * @param  stdClass  $row
     * @return list<string|float|null>
     */
    public function map($row): array
    {
        $rank = (int) ($row->export_rank ?? 0);

        if ($this->growthTab) {
            return [
                $rank,
                (string) ($row->client_code ?? ''),
                (string) ($row->label ?? ''),
                NumberDisplay::format($row->amount ?? null),
                NumberDisplay::format($row->prior_amount ?? null),
                $row->growth_pct !== null ? NumberDisplay::format($row->growth_pct, 1) : '',
                NumberDisplay::format($row->quantity ?? null),
                NumberDisplay::format($row->weight_total ?? null, 1),
                NumberDisplay::format($row->invoice_count ?? null),
                NumberDisplay::format($row->share_pct ?? null, 1),
            ];
        }

        $base = [
            $rank,
            (string) ($row->label ?? ''),
        ];

        if ($this->tab === 'clients') {
            $base[] = (string) ($row->client_code ?? '');
        }
        if ($this->tab === 'items') {
            $base[] = (string) ($row->secondary_label ?? '');
        }

        return array_merge($base, [
            NumberDisplay::format($row->amount ?? null),
            NumberDisplay::format($row->quantity ?? null),
            NumberDisplay::format($row->weight_total ?? null, 1),
            NumberDisplay::format($row->invoice_count ?? null),
            NumberDisplay::format($row->share_pct ?? null, 1),
        ]);
    }
}
