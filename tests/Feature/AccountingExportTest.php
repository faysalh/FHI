<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.accounting_sqlite.database', ':memory:');
        DB::purge('accounting_sqlite');
        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'accountant',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['accounting'],
        ]);
    }

    public function test_cash_pdf_export_returns_file(): void
    {
        $response = $this->get('/reports/accounting/export/cash/pdf?date=2026-06-07');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_summary_csv_export_returns_file(): void
    {
        $response = $this->get('/reports/accounting/export/summary/csv?date_from=2026-06-01&date_to=2026-06-07');

        $response->assertOk();
        $contentType = (string) $response->headers->get('content-type');
        $this->assertTrue(
            str_contains($contentType, 'text/csv') || str_contains($contentType, 'text/plain'),
            'Expected CSV download content type, got: '.$contentType
        );
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }
}
