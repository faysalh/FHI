<?php

declare(strict_types=1);

namespace App\Support;

use stdClass;

/**
 * Sorting and per-city visit counts for the visits report export.
 */
final class VisitsReportGrouping
{
    /**
     * @param  list<stdClass>  $rows
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     * @return list<stdClass>
     */
    public static function sortForExport(array $rows, array $monthSegments, bool $multiMonth, bool $sortByCity): array
    {
        if ($sortByCity) {
            return self::sortByCityThenNotVisitedFirst($rows, $monthSegments, $multiMonth);
        }

        return self::sortNotVisitedFirst($rows, $monthSegments, $multiMonth);
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     * @return array<string, array{visited: list<int>, not_visited: list<int>, clients: int}>
     */
    public static function perCityTotals(array $rows, array $monthSegments, bool $multiMonth): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $city = self::normalizeCity($row);
            if (! isset($totals[$city])) {
                $totals[$city] = [
                    'visited' => self::emptyCountList($monthSegments, $multiMonth),
                    'not_visited' => self::emptyCountList($monthSegments, $multiMonth),
                    'clients' => 0,
                ];
            }

            $totals[$city]['clients']++;

            if ($multiMonth) {
                foreach ($monthSegments as $i => $seg) {
                    $alias = (string) ($seg['sql_alias'] ?? '');
                    $hit = self::readMonthFlag($row, $alias);
                    if ($hit) {
                        $totals[$city]['visited'][$i]++;
                    } else {
                        $totals[$city]['not_visited'][$i]++;
                    }
                }
            } else {
                $hit = (int) ($row->visited ?? 0) === 1;
                if ($hit) {
                    $totals[$city]['visited'][0]++;
                } else {
                    $totals[$city]['not_visited'][0]++;
                }
            }
        }

        return $totals;
    }

    public static function normalizeCity(stdClass $row): string
    {
        $city = trim((string) ($row->city ?? ''));

        return $city !== '' ? $city : '(No city)';
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     * @return list<stdClass>
     */
    private static function sortNotVisitedFirst(array $rows, array $monthSegments, bool $multiMonth): array
    {
        $withoutVisits = [];
        $withVisits = [];

        foreach ($rows as $row) {
            if (self::hasAnyVisitInSelectedPeriod($row, $monthSegments, $multiMonth)) {
                $withVisits[] = $row;
            } else {
                $withoutVisits[] = $row;
            }
        }

        return array_merge($withoutVisits, $withVisits);
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     * @return list<stdClass>
     */
    private static function sortByCityThenNotVisitedFirst(array $rows, array $monthSegments, bool $multiMonth): array
    {
        /** @var array<string, list<stdClass>> $byCity */
        $byCity = [];

        foreach ($rows as $row) {
            $city = self::normalizeCity($row);
            $byCity[$city][] = $row;
        }

        $cityNames = array_keys($byCity);
        usort($cityNames, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        $sorted = [];
        foreach ($cityNames as $city) {
            $sorted = array_merge(
                $sorted,
                self::sortNotVisitedFirst($byCity[$city], $monthSegments, $multiMonth)
            );
        }

        return $sorted;
    }

    /**
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     */
    public static function hasAnyVisitInSelectedPeriod(stdClass $row, array $monthSegments, bool $multiMonth): bool
    {
        if (! $multiMonth) {
            return (int) ($row->visited ?? 0) === 1;
        }

        foreach ($monthSegments as $segment) {
            $alias = (string) ($segment['sql_alias'] ?? '');
            if ($alias === '') {
                continue;
            }

            if (self::readMonthFlag($row, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{key: string, label: string, label_en?: string, from: string, to: string, sql_alias: string}>  $monthSegments
     * @return list<int>
     */
    private static function emptyCountList(array $monthSegments, bool $multiMonth): array
    {
        $count = $multiMonth ? count($monthSegments) : 1;

        return array_fill(0, max(1, $count), 0);
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
