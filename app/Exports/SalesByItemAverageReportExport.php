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

class SalesByItemAverageReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $rows
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly int $workingDays
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
        if ($this->workingDays > 0) {
            return [
                'Category',
                'Item name',
                'Quantity (pcs)',
                'Avg quantity / working day (pcs)',
                'Balance coverage (days)',
            ];
        }

        return [
            'Category',
            'Item name',
            'Quantity (pcs)',
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string|float|int>
     */
    public function map($row): array
    {
        $units = (float) ($row->units_sold ?? 0);
        $storage = (float) ($row->storage_balance ?? 0);

        if ($this->workingDays > 0) {
            $base = [
                (string) ($row->category_name ?? ''),
                (string) ($row->item_name ?? ''),
                NumberDisplay::format($units),
            ];

            $avgUnits = $units / $this->workingDays;
            $base[] = NumberDisplay::format($avgUnits);
            $base[] = $avgUnits > 0
                ? NumberDisplay::format($storage / $avgUnits)
                : '—';

            return $base;
        }

        $base = [
            (string) ($row->category_name ?? ''),
            (string) ($row->item_name ?? ''),
            NumberDisplay::format($units),
        ];

        return $base;
    }
}
