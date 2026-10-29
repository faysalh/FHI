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
}
