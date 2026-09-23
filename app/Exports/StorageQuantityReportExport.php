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

class StorageQuantityReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    private int $rowNumber = 0;

    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $rows
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly bool $isAdv = false,
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
        return ['use_bom' => true];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = ['#', 'Category', 'Item code', 'Item name', 'Storage', 'Mode', 'Balance'];
        if (! $this->isAdv) {
            $headings[] = 'In store';
        }

        return $headings;
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $this->rowNumber++;
        $mapped = [
            (string) $this->rowNumber,
            (string) ($row->category_name ?? ''),
            (string) ($row->item_code ?? ''),
            (string) ($row->item_name ?? ''),
            (string) ($row->storage_name ?? ''),
            (string) ($row->balance_mode ?? ''),
            NumberDisplay::format((float) ($row->balance ?? 0)),
        ];
        if (! $this->isAdv) {
            $mapped[] = NumberDisplay::format((float) ($row->in_store ?? 0));
        }

        return $mapped;
    }
}
