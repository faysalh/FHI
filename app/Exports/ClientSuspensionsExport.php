<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use stdClass;

class ClientSuspensionsExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
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
            'Client',
            'Code',
            'Salesman',
            'Suspended by',
            'Suspended at',
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        return [
            (string) ($row->client_name ?? ''),
            (string) ($row->client_code ?? ''),
            (string) ($row->salesman_name ?? ''),
            (string) ($row->suspended_by ?? ''),
            (string) ($row->suspended_at ?? ''),
        ];
    }
}
