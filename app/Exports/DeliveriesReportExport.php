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

class DeliveriesReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $rows
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly bool $includeAmount = false,
        private readonly bool $includeWeight = false
    ) {
    }

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
        $headers = [
            'Invoice number',
            'Date',
            'Client code',
            'Client name',
            'City',
            'Storage',
            'Quantity (pcs)',
            'Delivery status',
            'Team',
        ];

        if ($this->includeAmount) {
            $headers[] = 'Amount';
        }
        if ($this->includeWeight) {
            $headers[] = 'Weight';
        }

        return $headers;
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $mapped = [
            (string) ($row->invoice_no ?? $row->invoice_id ?? ''),
            (string) ($row->document_date ?? ''),
            (string) ($row->client_code ?? ''),
            (string) ($row->client_name ?? ''),
            (string) ($row->city_name ?? ''),
            (string) ($row->storage_name ?? ''),
            NumberDisplay::format((float) ($row->quantity ?? 0)),
            (string) ($row->delivery_status ?? ''),
            (string) ($row->team_name ?? ''),
        ];

        if ($this->includeAmount) {
            $mapped[] = NumberDisplay::format((float) ($row->amount ?? 0));
        }
        if ($this->includeWeight) {
            $mapped[] = NumberDisplay::format((float) ($row->weight_total ?? 0));
        }

        return $mapped;
    }
}

