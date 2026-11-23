<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ComparisonReportTest extends TestCase
{
    public function test_comparison_report_opens_the_asan_comparison(): void
    {
        $this->get('/reports/comparison')
            ->assertRedirect('/reports/comparison/asan');
    }

    public function test_comparison_report_keeps_filters_when_opening_asan(): void
    {
        $this->get('/reports/comparison?exclude_category=Frozen')
            ->assertRedirect('/reports/comparison/asan?exclude_category=Frozen');
    }

    public function test_asan_comparison_uses_one_range_and_names_each_month(): void
    {
        $response = $this->get('/reports/comparison/asan?date_from=2026-09-01&date_to=2026-11-22');

        $response->assertOk();
        $response->assertSee('name="date_from"', false);
        $response->assertSee('value="2026-09-01"', false);
        $response->assertSee('value="2026-11-22"', false);
        $response->assertDontSee('Period 1 from');
        $response->assertSee('Sep 2026');
        $response->assertSee('Oct 2026');
        $response->assertSee('Nov 2026');
        $response->assertSee('2026-09-01 to 2026-09-30');
        $response->assertSee('2026-11-01 to 2026-11-22');
    }
}
