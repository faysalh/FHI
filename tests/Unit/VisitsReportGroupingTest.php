<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\VisitsReportGrouping;
use PHPUnit\Framework\TestCase;
use stdClass;

class VisitsReportGroupingTest extends TestCase
{
    public function test_sort_by_city_places_not_visited_first_within_each_city(): void
    {
        $rows = [
            $this->row('B', 'Client B visited', 1),
            $this->row('A', 'Client A missed', 0),
            $this->row('B', 'Client B missed', 0),
            $this->row('A', 'Client A visited', 1),
        ];

        $sorted = VisitsReportGrouping::sortForExport($rows, [], false, true);

        $this->assertSame(
            ['A', 'A', 'B', 'B'],
            array_map(static fn (stdClass $r): string => (string) $r->city, $sorted)
        );
        $this->assertSame(
            ['Client A missed', 'Client A visited', 'Client B missed', 'Client B visited'],
            array_map(static fn (stdClass $r): string => (string) $r->client_name, $sorted)
        );
    }

    public function test_sort_without_city_keeps_global_not_visited_first(): void
    {
        $rows = [
            $this->row('B', 'Visited B', 1),
            $this->row('A', 'Missed A', 0),
            $this->row('B', 'Missed B', 0),
        ];

        $sorted = VisitsReportGrouping::sortForExport($rows, [], false, false);

        $this->assertSame(
            ['Missed A', 'Missed B', 'Visited B'],
            array_map(static fn (stdClass $r): string => (string) $r->client_name, $sorted)
        );
    }

    public function test_per_city_totals_count_visited_and_not_visited(): void
    {
        $rows = [
            $this->row('Erbil', 'A', 1),
            $this->row('Erbil', 'B', 0),
            $this->row('Duhok', 'C', 0),
        ];

        $totals = VisitsReportGrouping::perCityTotals($rows, [], false);

        $this->assertSame(1, $totals['Duhok']['visited'][0]);
        $this->assertSame(0, $totals['Duhok']['not_visited'][0]);
        $this->assertSame(1, $totals['Erbil']['visited'][0]);
        $this->assertSame(1, $totals['Erbil']['not_visited'][0]);
        $this->assertSame(1, $totals['Erbil']['clients']);
    }

    private function row(string $city, string $name, int $visited): stdClass
    {
        $row = new stdClass;
        $row->city = $city;
        $row->client_name = $name;
        $row->visited = $visited;

        return $row;
    }
}
