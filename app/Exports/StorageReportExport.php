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

class StorageReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    private int $rowNumber = 0;

    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $rows
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly bool $showCategory = false,
        private readonly bool $showItemCode = false,
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
        $headings = ['#'];
        if ($this->showCategory) {
            $headings[] = 'Category';
        }
        if ($this->showItemCode) {
            $headings[] = 'Item code';
        }
        $headings[] = 'Item name';
        $headings[] = 'Quantity (carton)';
        $headings[] = 'Weight (kg)';

        return $headings;
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $this->rowNumber++;

        $mapped = [(string) $this->rowNumber];
        if ($this->showCategory) {
            $mapped[] = (string) ($row->category_name ?? '');
        }
        if ($this->showItemCode) {
            $mapped[] = (string) ($row->item_code ?? '');
        }
        $mapped[] = (string) ($row->item_name ?? '');
        $mapped[] = NumberDisplay::format((float) ($row->quantity_total ?? 0));
        $mapped[] = NumberDisplay::format((float) ($row->weight_total ?? 0));

        return $mapped;
    }
    }
}
