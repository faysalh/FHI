<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ComparisonMonthRows;
use App\Support\ComparisonMonthSpan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComparisonMonthSpanTest extends TestCase
{
    #[Test]
    public function test_october_first_through_november_22_compares_two_months(): void
    {
        $months = ComparisonMonthSpan::between('2026-10-01', '2026-11-22');

        $this->assertSame(['2026-10', '2026-11'], array_column($months, 'key'));
        $this->assertSame('2026-10-01', $months[0]['from']);
        $this->assertSame('2026-10-31', $months[0]['to']);
        $this->assertSame('2026-11-01', $months[1]['from']);
        $this->assertSame('2026-11-22', $months[1]['to']);
        $this->assertSame('Nov 2026 − Oct 2026', ComparisonMonthSpan::changeLabel($months));
    }

    #[Test]
    public function test_september_first_through_november_22_compares_three_months(): void
    {
        $months = ComparisonMonthSpan::between('2026-09-01', '2026-11-22');

        $this->assertSame(['2026-09', '2026-10', '2026-11'], array_column($months, 'key'));
        $this->assertSame('2026-09-30', $months[0]['to']);
        $this->assertSame('2026-10-01', $months[1]['from']);
        $this->assertSame('2026-10-31', $months[1]['to']);
        $this->assertSame('2026-11-22', $months[2]['to']);
    }

    #[Test]
    public function test_a_partial_first_month_starts_on_the_chosen_day(): void
    {
        $months = ComparisonMonthSpan::between('2026-10-15', '2026-11-22');

        $this->assertSame('2026-10-15', $months[0]['from']);
        $this->assertSame('2026-10-31', $months[0]['to']);
        $this->assertSame('2026-11-01', $months[1]['from']);
    }

    #[Test]
    public function test_change_is_the_latest_month_minus_the_month_before_it(): void
    {
        $slices = ComparisonMonthSpan::between('2026-09-01', '2026-11-22');
        $rows = ComparisonMonthRows::merge($slices, [
            '2026-09' => [(object) ['category_name' => 'Fresh', 'item_name' => 'Whole', 'quantity_total' => 10, 'amount_total' => 100, 'weight_total' => 1]],
            '2026-10' => [(object) ['category_name' => 'Fresh', 'item_name' => 'Whole', 'quantity_total' => 20, 'amount_total' => 200, 'weight_total' => 2]],
            '2026-11' => [(object) ['category_name' => 'Fresh', 'item_name' => 'Whole', 'quantity_total' => 15, 'amount_total' => 150, 'weight_total' => 1.5]],
        ]);

        $this->assertSame(10.0, $rows[0]->months['2026-09']['quantity']);
        $this->assertSame(20.0, $rows[0]->months['2026-10']['quantity']);
        $this->assertSame(15.0, $rows[0]->months['2026-11']['quantity']);
        $this->assertSame(-5.0, $rows[0]->diff_quantity);

        $totals = ComparisonMonthRows::totals($rows, $slices);
        $this->assertSame(-25.0, $totals['growth_quantity']);
    }
}
