<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\InvoiceBrandingSettingsController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ReportPdfBranding
{
    /**
     * @return array<string, string>
     */
    public static function settings(): array
    {
        return InvoiceBrandingSettingsController::getSettings();
    }

    /**
     * @param  array<string, string>|null  $branding
     * @return array{branding: array<string, string>, brandingLogoDataUri: string|null}
     */
    public static function viewData(?array $branding = null): array
    {
        $branding ??= self::settings();

        return [
            'branding' => $branding,
            'brandingLogoDataUri' => self::logoDataUri($branding),
        ];
    }

    /**
     * @param  array<string, string>  $branding
     */
    public static function logoDataUri(array $branding): ?string
    {
        $logoPath = trim((string) ($branding['logo_path'] ?? ''));
        if ($logoPath === '') {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($logoPath)) {
                return null;
            }
            $mime = (string) ($disk->mimeType($logoPath) ?? 'image/png');
            $contents = $disk->get($logoPath);

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (Throwable $e) {
            Log::warning('report_pdf_logo_data_uri_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
