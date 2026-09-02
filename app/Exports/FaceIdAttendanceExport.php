<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class FaceIdAttendanceExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  list<object>  $rows
     */
    public function __construct(private readonly array $rows) {}

    public function collection(): Collection
    {
        return Collection::make($this->rows);
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
            'Employee',
            'Code',
            'Event',
            'Recorded at',
            'Confidence',
        ];
    }

    /**
     * @param  object  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $event = (string) ($row->event_type ?? '');
        $eventLabel = $event === 'clock_in' ? 'Clock in' : ($event === 'clock_out' ? 'Clock out' : $event);

        return [
            (string) ($row->employee_name ?? ''),
            (string) ($row->employee_code ?? ''),
            $eventLabel,
            (string) ($row->recorded_at ?? ''),
            $row->confidence !== null ? number_format((float) $row->confidence, 4) : '',
        ];
    }
}
