<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\NumberDisplay;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CustomersReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    private int $rowNumber = 0;

    /**
     * @param  list<object|array<string, mixed>>  $rows
     * @param  list<string>  $columns
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $columns
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
        return array_map(
            static fn (string $column): string => strtoupper(str_replace('_', ' ', $column)),
            $this->columns
        );
    }

    /**
     * @param  object|array<string, mixed>  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $mapped = [(string) (++$this->rowNumber)];
        $data = (array) $row;
        foreach ($this->columns as $column) {
            $value = $data[$column] ?? '';
            if ($value !== null && ! is_bool($value) && is_numeric($value)) {
                $mapped[] = NumberDisplay::format($value);
            } else {
                $mapped[] = (string) $value;
            }
        }

        return $mapped;
    }
}
