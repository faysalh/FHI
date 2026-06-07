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

class StorageItemsReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    private int $rowNumber = 0;

    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $rows
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly int $workingDays
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
        return [
            '#',
            'Item code',
            'Category',
            'Item name',
            'Carton',
            'Sales',
            'Sales average ('.$this->workingDays.' WD)',
            'Forecast',
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $this->rowNumber++;
        $wd = max(1, $this->workingDays);
        $sold = (float) ($row->sold_quantity_period ?? 0);
        $qty = (float) ($row->quantity_total ?? 0);
        $avg = $sold / $wd;
        $cover = ($sold > 0) ? ($qty / $avg) : null;

        return [
            (string) $this->rowNumber,
            (string) ($row->item_code ?? ''),
            (string) ($row->category_name ?? ''),
            (string) ($row->item_name ?? ''),
            NumberDisplay::format($qty),
            NumberDisplay::format($sold),
            NumberDisplay::format($avg),
            $cover !== null ? NumberDisplay::format($cover) : '',
        ];
    }
}
