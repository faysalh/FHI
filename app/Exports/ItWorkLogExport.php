<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\ItWorkLogSqliteService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use stdClass;

class ItWorkLogExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    private int $rowNumber = 0;

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
            '#',
            'Title',
            'Notes',
            'Started',
            'Finished',
            'Duration',
            'Status',
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $finishedAt = trim((string) ($row->finished_at ?? ''));
        $startedAt = (string) ($row->started_at ?? '');

        return [
            (string) (++$this->rowNumber),
            (string) ($row->title ?? ''),
            (string) ($row->notes ?? ''),
            $startedAt,
            $finishedAt !== '' ? $finishedAt : '',
            ItWorkLogSqliteService::formatDuration($startedAt, $finishedAt !== '' ? $finishedAt : null),
            $finishedAt !== '' ? 'Finished' : 'In progress',
        ];
    }
}
