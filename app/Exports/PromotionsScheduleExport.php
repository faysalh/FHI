<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithTitle;

class PromotionsScheduleExport implements FromArray, WithCustomCsvSettings, WithTitle
{
    /**
     * @param  array{
     *     promoter: object,
     *     week_start: string,
     *     week_end: string,
     *     columns: list<array{weekday: int, date: string, label: string}>,
     *     cells: array<int, list<string>>,
     *     max_rows: int
     * }  $sheet
     */
    public function __construct(
        private readonly array $sheet
    ) {}

    public function array(): array
    {
        $columns = $this->sheet['columns'];
        $cells = $this->sheet['cells'];
        $maxRows = max(1, (int) ($this->sheet['max_rows'] ?? 0));

        $header = array_map(static fn (array $col): string => (string) ($col['label'] ?? ''), $columns);
        $rows = [$header];

        for ($i = 0; $i < $maxRows; $i++) {
            $row = [];
            foreach ($columns as $column) {
                $weekday = (int) ($column['weekday'] ?? 0);
                $names = $cells[$weekday] ?? [];
                $row[] = (string) ($names[$i] ?? '');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function title(): string
    {
        $name = trim((string) ($this->sheet['promoter']->employee_name ?? 'Promoter'));
        $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]\\:]/', '-', $name) ?? 'Promoter';

        return mb_substr($name, 0, 31);
    }

  /**
     * @return array<string, bool>
     */
    public function getCsvSettings(): array
    {
        return ['use_bom' => true];
    }
}
