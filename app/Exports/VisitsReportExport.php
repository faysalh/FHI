<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use stdClass;

class VisitsReportExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $rows
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly array $monthSegments,
        private readonly bool $multiMonth
    ) {
    }

    public function collection(): Collection
    {
        return Collection::make($this->rows);
    }

    /**
     * UTF-8 BOM so Excel on Windows opens Arabic text in columns correctly.
     *
     * @return array<string, mixed>
     */
    public function getCsvSettings(): array
    {
        return [
            'use_bom' => true,
        ];
    }

    /**
     * @param  stdClass  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $out = [
            (string) ($row->client_code ?? ''),
            (string) ($row->client_name ?? ''),
            (string) ($row->city ?? ''),
            (string) ($row->salesman_name ?? ''),
        ];

        if ($this->multiMonth) {
            foreach ($this->monthSegments as $seg) {
                $v = self::readMonthFlag($row, (string) $seg['sql_alias']);
                $out[] = $v ? 'Visited' : 'Not visited';
            }
        } else {
            $v = (int) ($row->visited ?? 0) === 1;
            $out[] = $v ? 'Visited' : 'Not visited';
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = [
            'Client code',
            'Client name',
            'City',
            'Salesman',
        ];

        if ($this->multiMonth) {
            foreach ($this->monthSegments as $seg) {
                $headings[] = (string) $seg['label'];
            }
        } else {
            $headings[] = 'Visit status';
        }

        return $headings;
    }

    private static function readMonthFlag(stdClass $row, string $sqlAlias): bool
    {
        foreach ([$sqlAlias, strtolower($sqlAlias)] as $key) {
            if (property_exists($row, $key)) {
                return (int) $row->{$key} === 1;
            }
        }

        return false;
    }
}
