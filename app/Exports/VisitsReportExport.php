<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\VisitsReportGrouping;
use App\Support\VisitsReportRowValues;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use stdClass;

class VisitsReportExport implements FromArray, WithCustomCsvSettings, WithHeadings
{
    /** @var list<list<string|int>> */
    private array $dataRows;

    /**
     * @param  list<stdClass>  $rows
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $monthSegments,
        private readonly bool $multiMonth,
        private readonly bool $groupSubtotalsByCity = false,
        private readonly bool $includeMonthSales = false
    ) {
        $this->dataRows = $this->buildDataRows();
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    public function array(): array
    {
        return $this->dataRows;
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
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = [
            '#',
            'Client code',
            'Client name',
            'City',
            'Address',
        ];

        if ($this->multiMonth) {
            foreach ($this->monthSegments as $seg) {
                $headings[] = (string) $seg['label'].' — visit';
                if ($this->includeMonthSales) {
                    $headings[] = (string) $seg['label'].' — sales';
                }
            }
        } else {
            $headings[] = 'Visit status';
            if ($this->includeMonthSales) {
                $headings[] = 'Sales (period)';
            }
        }

        return $headings;
    }

    /**
     * @return list<list<string|int>>
     */
    private function buildDataRows(): array
    {
        if ($this->groupSubtotalsByCity) {
            return $this->buildGroupedByCityDataRows();
        }

        $cols = count($this->headings());
        $out = [];
        $n = 1;
        foreach ($this->rows as $row) {
            $out[] = self::mapDataRow($n, $row, $this->monthSegments, $this->multiMonth, $this->includeMonthSales);
            $n++;
        }

        $blank = array_fill(0, $cols, '');
        $out[] = $blank;

        [$visited, $notVisited, $clients, $salesSums] = self::summarize(
            $this->rows,
            $this->monthSegments,
            $this->multiMonth
        );

        $out[] = self::summaryLine('Total visited', $cols, $visited, $this->includeMonthSales, $this->multiMonth, $salesSums);
        $out[] = self::summaryLine('Total not visited', $cols, $notVisited, $this->includeMonthSales, $this->multiMonth);
        $out[] = self::clientsTotalLine($cols, $clients);

        return $out;
    }

    /**
     * @return list<list<string|int>>
     */
    private function buildGroupedByCityDataRows(): array
    {
        $cols = count($this->headings());
        $out = [];
        $n = 1;
        $currentCity = null;
        /** @var list<stdClass> $cityRows */
        $cityRows = [];

        $flushCity = function () use (&$out, &$n, &$cityRows, &$currentCity, $cols): void {
            if ($currentCity === null || $cityRows === []) {
                return;
            }

            foreach ($cityRows as $row) {
                $out[] = self::mapDataRow($n, $row, $this->monthSegments, $this->multiMonth, $this->includeMonthSales);
                $n++;
            }

            [$visited, $notVisited, $clients, $salesSums] = self::summarize(
                $cityRows,
                $this->monthSegments,
                $this->multiMonth
            );

            foreach (self::citySummaryRows($currentCity, $cols, $visited, $notVisited, $clients, $this->includeMonthSales, $this->multiMonth, $salesSums) as $summaryRow) {
                $out[] = $summaryRow;
            }
            $cityRows = [];
        };

        foreach ($this->rows as $row) {
            $city = VisitsReportGrouping::normalizeCity($row);
            if ($currentCity !== null && $city !== $currentCity) {
                $flushCity();
            }
            $currentCity = $city;
            $cityRows[] = $row;
        }

        $flushCity();

        $blank = array_fill(0, $cols, '');
        $out[] = $blank;

        [$visited, $notVisited, $clients, $salesSums] = self::summarize(
            $this->rows,
            $this->monthSegments,
            $this->multiMonth
        );

        $out[] = self::summaryLine('Grand total visited', $cols, $visited, $this->includeMonthSales, $this->multiMonth, $salesSums);
        $out[] = self::summaryLine('Grand total not visited', $cols, $notVisited, $this->includeMonthSales, $this->multiMonth);
        $out[] = self::clientsTotalLine($cols, $clients, 'Grand total clients');

        return $out;
    }

    /**
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     * @return list<string|int>
     */
    private static function mapDataRow(int $num, stdClass $row, array $monthSegments, bool $multiMonth, bool $includeMonthSales): array
    {
        $out = [
            (string) $num,
            (string) ($row->client_code ?? ''),
            (string) ($row->client_name ?? ''),
            (string) ($row->city ?? ''),
            (string) ($row->client_address ?? ''),
        ];

        if ($multiMonth) {
            foreach ($monthSegments as $seg) {
                $v = VisitsReportRowValues::readMonthFlag($row, (string) $seg['sql_alias']);
                $out[] = $v ? 'Visited' : 'Not visited';
                if ($includeMonthSales) {
                    $out[] = display_number(VisitsReportRowValues::readSalesAmount($row, (string) $seg['sales_sql_alias']));
                }
            }
        } else {
            $v = (int) ($row->visited ?? 0) === 1;
            $out[] = $v ? 'Visited' : 'Not visited';
            if ($includeMonthSales) {
                $out[] = display_number(VisitsReportRowValues::readSalesAmount($row, 'month_sales'));
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $visited
     * @param  list<int>  $notVisited
     * @param  list<float>  $salesSums
     * @return list<list<string|int>>
     */
    private static function citySummaryRows(
        string $city,
        int $columnCount,
        array $visited,
        array $notVisited,
        int $clientCount,
        bool $includeMonthSales,
        bool $multiMonth,
        array $salesSums = []
    ): array {
        $row = array_fill(0, $columnCount, '');
        $row[0] = $city.' — visited';
        self::placeVisitSummaryCounts($row, $visited, 5, $includeMonthSales, $multiMonth);
        if ($includeMonthSales) {
            self::placeSalesSums($row, $salesSums, 5, $multiMonth);
        }

        $notRow = array_fill(0, $columnCount, '');
        $notRow[0] = $city.' — not visited';
        self::placeVisitSummaryCounts($notRow, $notVisited, 5, $includeMonthSales, $multiMonth);

        $clientRow = array_fill(0, $columnCount, '');
        $clientRow[0] = $city.' — clients';
        $visitSpan = self::visitColumnSpan($includeMonthSales, $multiMonth, count($visited));
        if ($columnCount > 5) {
            $clientRow[5] = $clientCount;
            if ($visitSpan > 1) {
                $clientRow[5 + $visitSpan - 1] = $clientCount;
            }
        } elseif ($columnCount > 0) {
            $clientRow[$columnCount - 1] = $clientCount;
        }

        return [$row, $notRow, $clientRow, array_fill(0, $columnCount, '')];
    }

    /**
     * @param  list<int>  $counts
     */
    private static function placeVisitSummaryCounts(array &$row, array $counts, int $startCol, bool $includeMonthSales, bool $multiMonth): void
    {
        $col = $startCol;
        foreach ($counts as $c) {
            $row[$col] = $c;
            $col += ($includeMonthSales && $multiMonth) ? 2 : 1;
        }
    }

    /**
     * Place amount sums in each visit section's sales column (unlabeled).
     *
     * @param  list<float>  $salesSums
     */
    private static function placeSalesSums(array &$row, array $salesSums, int $startCol, bool $multiMonth): void
    {
        if ($salesSums === []) {
            return;
        }

        if (! $multiMonth) {
            $row[$startCol + 1] = display_number($salesSums[0] ?? 0);

            return;
        }

        $col = $startCol;
        foreach ($salesSums as $sum) {
            $row[$col + 1] = display_number($sum);
            $col += 2;
        }
    }

    private static function visitColumnSpan(bool $includeMonthSales, bool $multiMonth, int $monthCount): int
    {
        if (! $multiMonth) {
            return $includeMonthSales ? 2 : 1;
        }

        return $monthCount * ($includeMonthSales ? 2 : 1);
    }

    /**
     * @param  list<int>  $counts  per visit column (single value or one per month)
     * @param  list<float>  $salesSums  amount sum per visit section (placed unlabeled in the sales columns)
     * @return list<string|int>
     */
    private static function summaryLine(string $label, int $columnCount, array $counts, bool $includeMonthSales, bool $multiMonth, array $salesSums = []): array
    {
        $row = array_fill(0, $columnCount, '');
        $row[0] = $label;
        self::placeVisitSummaryCounts($row, $counts, 5, $includeMonthSales, $multiMonth);
        if ($includeMonthSales) {
            self::placeSalesSums($row, $salesSums, 5, $multiMonth);
        }

        return $row;
    }

    /**
     * @return list<string|int>
     */
    private static function clientsTotalLine(int $columnCount, int $clientCount, string $label = 'Total clients'): array
    {
        $row = array_fill(0, $columnCount, '');
        $row[0] = $label;
        if ($columnCount > 5) {
            $row[5] = $clientCount;
        } elseif ($columnCount > 0) {
            $row[$columnCount - 1] = $clientCount;
        }

        return $row;
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     * @return array{0: list<int>, 1: list<int>, 2: int, 3: list<float>}
     */
    private static function summarize(array $rows, array $monthSegments, bool $multiMonth): array
    {
        $clientCount = count($rows);

        if ($multiMonth) {
            $visited = [];
            $notVisited = [];
            $sales = [];
            foreach ($monthSegments as $seg) {
                $alias = (string) ($seg['sql_alias'] ?? '');
                $visited[$alias] = 0;
                $notVisited[$alias] = 0;
                $sales[$alias] = 0.0;
            }
            foreach ($rows as $row) {
                foreach ($monthSegments as $seg) {
                    $alias = (string) ($seg['sql_alias'] ?? '');
                    $hit = self::readMonthFlag($row, $alias);
                    if ($hit) {
                        $visited[$alias]++;
                    } else {
                        $notVisited[$alias]++;
                    }
                    $sales[$alias] += VisitsReportRowValues::readSalesAmount($row, (string) ($seg['sales_sql_alias'] ?? ''));
                }
            }

            $visitedOrdered = [];
            $notVisitedOrdered = [];
            $salesOrdered = [];
            foreach ($monthSegments as $seg) {
                $alias = (string) ($seg['sql_alias'] ?? '');
                $visitedOrdered[] = $visited[$alias] ?? 0;
                $notVisitedOrdered[] = $notVisited[$alias] ?? 0;
                $salesOrdered[] = $sales[$alias] ?? 0.0;
            }

            return [$visitedOrdered, $notVisitedOrdered, $clientCount, $salesOrdered];
        }

        $v = 0;
        $n = 0;
        $salesSum = 0.0;
        foreach ($rows as $row) {
            $hit = (int) ($row->visited ?? 0) === 1;
            if ($hit) {
                $v++;
            } else {
                $n++;
            }
            $salesSum += VisitsReportRowValues::readSalesAmount($row, 'month_sales');
        }

        return [[$v], [$n], $clientCount, [$salesSum]];
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
