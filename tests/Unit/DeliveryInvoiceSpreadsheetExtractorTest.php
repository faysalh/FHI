<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DeliveryInvoiceSpreadsheetExtractor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DeliveryInvoiceSpreadsheetExtractorTest extends TestCase
{
    public function test_csv_extracts_invoice_numbers_and_skips_header(): void
    {
        $csv = "Invoice Number\n53267\n53265\n\n53264\n";
        $path = tempnam(sys_get_temp_dir(), 'batch_csv_');
        $this->assertNotFalse($path);
        file_put_contents($path, $csv);

        $file = new UploadedFile($path, 'invoices.csv', 'text/csv', null, true);
        $numbers = (new DeliveryInvoiceSpreadsheetExtractor)->extractInvoiceNumbersFromUpload($file);

        $this->assertSame(['53267', '53265', '53264'], $numbers);
    }

    public function test_csv_uses_first_non_empty_cell_per_row(): void
    {
        $csv = ",53267\n,53265\n";
        $path = tempnam(sys_get_temp_dir(), 'batch_csv_');
        $this->assertNotFalse($path);
        file_put_contents($path, $csv);

        $file = new UploadedFile($path, 'invoices.csv', 'text/csv', null, true);
        $numbers = (new DeliveryInvoiceSpreadsheetExtractor)->extractInvoiceNumbersFromUpload($file);

        $this->assertSame(['53267', '53265'], $numbers);
    }
}
