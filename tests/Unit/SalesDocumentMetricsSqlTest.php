<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SalesDocumentMetricsSql;
use PHPUnit\Framework\TestCase;

class SalesDocumentMetricsSqlTest extends TestCase
{
    public function test_salesman_tier_report_uses_header_discount_only_for_market_tier(): void
    {
        $metrics = new SalesDocumentMetricsSql;
        $expr = $metrics->salesmanTierReportLineAmountExpr('client_tier', 'd', 'inv');

        $this->assertStringContainsString('client_tier', $expr);
        $this->assertStringContainsString('= 3 THEN', $expr);
        $this->assertStringContainsString('inv_hdr', $expr);
        $this->assertStringNotContainsString('inv_extra', $expr);
        $this->assertStringContainsString('fld_store_document_discount_percent', $expr);
        $this->assertStringContainsString('COALESCE(inv.inv_hdr, 0) = 0', $expr);
    }

    public function test_sales_line_amount_still_includes_extra_discount_for_main_sales_report(): void
    {
        $metrics = new SalesDocumentMetricsSql;
        $expr = $metrics->salesLineAmountExpr('d', 'inv');

        $this->assertStringContainsString('inv_hdr', $expr);
        $this->assertStringContainsString('inv_extra', $expr);
    }

    public function test_header_discount_amount_excludes_extra_discount(): void
    {
        $metrics = new SalesDocumentMetricsSql;
        $expr = $metrics->salesLineHeaderDiscountAmountExpr('d', 'inv');

        $this->assertStringContainsString('inv_hdr', $expr);
        $this->assertStringNotContainsString('inv_extra', $expr);
    }
}
