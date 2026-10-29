<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReportPdfBranding;
use Tests\TestCase;

class ReportPdfBrandingTest extends TestCase
{
    public function test_other_reports_do_not_receive_the_invoice_logo_fallback(): void
    {
        $this->assertNull(ReportPdfBranding::logoDataUri(['logo_path' => '']));

        $view = ReportPdfBranding::viewData([
            'company_name' => 'N.Z.Y. Company',
            'logo_path' => '',
        ]);

        $this->assertNull($view['brandingLogoDataUri']);
    }

    public function test_invoice_export_falls_back_to_the_jpeg_logo(): void
    {
        $uri = ReportPdfBranding::logoDataUri(['logo_path' => ''], true);

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $uri);
    }

    public function test_for_current_request_returns_branding_settings(): void
    {
        $settings = ReportPdfBranding::forCurrentRequest();

        $this->assertArrayHasKey('company_name', $settings);
        $this->assertArrayHasKey('logo_path', $settings);
    }
}
