<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use stdClass;

class HrLeaveExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
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
            'Employee',
            'Type',
            'When',
            'Length',
            'Reason',
            'Status',
            'Requested by',
            'Decided by',
            'Decided at',
            'Decision note',
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string|int>
     */
    public function map($row): array
    {
        return [
            (string) ($row->employee_name ?? ''),
            (string) ($row->type_label ?? ''),
            (string) ($row->span_label ?? ''),
            (string) ($row->duration_label ?? ''),
            (string) ($row->reason ?? ''),
            (string) ($row->status_label ?? ''),
            (string) ($row->requested_by_name ?? ''),
            (string) ($row->decided_by_name ?? ''),
            (string) ($row->decided_at ?? ''),
            (string) ($row->decision_note ?? ''),
        ];
    }
}
