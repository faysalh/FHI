<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_legacy_dashboard_url_redirects_to_dashboard_lab(): void
    {
        $response = $this->get('/reports/dashboard');

        $response->assertRedirect('/reports/dashboard-lab');
    }

    public function test_legacy_dashboard_metrics_url_redirects_to_dashboard_lab_metrics(): void
    {
        $response = $this->get('/reports/dashboard/metrics');

        $response->assertRedirect('/reports/dashboard-lab/metrics');
    }
}
