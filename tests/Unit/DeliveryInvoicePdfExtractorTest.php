<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DeliveryInvoicePdfExtractor;
use Tests\TestCase;

class DeliveryInvoicePdfExtractorTest extends TestCase
{
    private const SAMPLE_DELIVERY_REPORT = <<<'TEXT'
Invoice Delivery Report
Branch: Hawler
Report # 1289
Report Date 2026-05-26 10:02
Driver Zirak
Car Model Kia 4000S
Plate Number 22E25584
Invoice Count 39
Invoices
# Invoice Number Pickup Time Notes
1 53267 10:03:06
2 53265 10:03:14
3 53264 10:03:17

-- 1 of 2 --

# Invoice Number Pickup Time Notes
17 53162 10:04:04
18 53283 10:04:09
Generated on: 2026-05-26 10:07:35
TEXT;

    public function test_delivery_report_extracts_table_invoice_numbers_only(): void
    {
        $extractor = new DeliveryInvoicePdfExtractor();
        $method = new \ReflectionMethod(DeliveryInvoicePdfExtractor::class, 'extractFromDeliveryReportTable');
        $method->setAccessible(true);

        /** @var list<string> $numbers */
        $numbers = $method->invoke($extractor, self::SAMPLE_DELIVERY_REPORT);

        $this->assertSame(['53267', '53265', '53264', '53162', '53283'], $numbers);
        $this->assertNotContains('1289', $numbers);
        $this->assertNotContains('2026-05-26', $numbers);
    }

    public function test_sample_pdf_skips_report_header_numbers(): void
    {
        $path = 'e:/ادخال مخزني/زيرةك.pdf';
        if (! is_readable($path)) {
            $this->markTestSkipped('Sample delivery PDF is not available on this machine.');
        }

        $numbers = (new DeliveryInvoicePdfExtractor())->extractInvoiceNumbers($path);

        $this->assertCount(39, $numbers);
        $this->assertNotContains('1289', $numbers);
        $this->assertNotContains('2026-05-26', $numbers);
        $this->assertContains('53267', $numbers);
        $this->assertContains('53281', $numbers);
    }
}
