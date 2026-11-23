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

class ItemBasedSalesReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  list<stdClass>  $rows
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
            'Item code',
            'Item name',
            'Category',
            'Sales qty',
            'Free qty',
            'Discount',
            'Sales amount',
            'Return qty',
            'Return amount',
            'Net qty',
            'Net amount',
            'Avg price',
            'Weight (kg)',
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $netQty = (float) $row->sales_qty - (float) $row->return_qty;
        $netAmount = (float) $row->sales_amount - (float) $row->return_amount;

        return [
            (string) ($row->item_code ?? ''),
            (string) ($row->item_name ?? ''),
            (string) ($row->category_name ?? ''),
            NumberDisplay::format((float) $row->sales_qty),
            NumberDisplay::format((float) $row->free_qty),
            NumberDisplay::format((float) $row->sales_discount),
            NumberDisplay::format((float) $row->sales_amount),
            NumberDisplay::format((float) $row->return_qty),
            NumberDisplay::format((float) $row->return_amount),
            NumberDisplay::format($netQty),
            NumberDisplay::format($netAmount),
            $netQty != 0.0 ? NumberDisplay::format($netAmount / $netQty) : '',
            NumberDisplay::format((float) $row->weight_total),
        ];
    }
}
