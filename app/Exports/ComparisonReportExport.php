<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ComparisonReportExport implements FromArray, WithCustomCsvSettings, WithHeadings
{
    /**
     * @param  array<int, array<int, string|int|float>>  $rows
     * @param  list<string>  $headings
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $headings
    ) {}

    /**
     * @return array<int, array<int, string|int|float>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return array{use_bom: bool}
     */
    public function getCsvSettings(): array
    {
        return ['use_bom' => true];
    }
}
