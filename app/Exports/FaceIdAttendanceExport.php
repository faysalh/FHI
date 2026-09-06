<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\ReportingTime;
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
            'Latitude',
            'Longitude',
            'Location accuracy (m)',
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

        $latitude = isset($row->latitude) ? (float) $row->latitude : null;
        $longitude = isset($row->longitude) ? (float) $row->longitude : null;
        $accuracy = isset($row->location_accuracy) ? (float) $row->location_accuracy : null;

        return [
            (string) ($row->employee_name ?? ''),
            (string) ($row->employee_code ?? ''),
            $eventLabel,
            ReportingTime::formatStored(isset($row->recorded_at) ? (string) $row->recorded_at : null),
            $latitude !== null ? (string) $latitude : '',
            $longitude !== null ? (string) $longitude : '',
            $accuracy !== null ? (string) $accuracy : '',
            $row->confidence !== null ? number_format((float) $row->confidence, 4) : '',
        ];
    }
}
