<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SalesByItemReportTest extends TestCase
{
    public function test_sales_by_item_page_redirects_to_the_item_sales_report(): void
    {
        $this->get('/reports/sales-by-item?date_from=2026-04-01&date_to=2026-04-20')
            ->assertRedirect('/reports/sales?date_from=2026-04-01&date_to=2026-04-20');
    }

    public function test_sales_by_item_exports_redirect_to_the_item_sales_exports(): void
    {
        $this->get('/reports/sales-by-item/export/csv?date_from=2026-04-01&date_to=2026-04-20')
            ->assertRedirect('/reports/sales/export/csv?date_from=2026-04-01&date_to=2026-04-20');

        $this->get('/reports/sales-by-item/export/pdf?date_from=2026-04-01&date_to=2026-04-20')
            ->assertRedirect('/reports/sales/export/pdf?date_from=2026-04-01&date_to=2026-04-20');
    }
}
