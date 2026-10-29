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
     * Settings array used by report PDFs that pass branding themselves.
     *
     * @return array<string, string>
     */
    public static function forCurrentRequest(): array
    {
        return self::settings();
    }

    /**
     * @param  array<string, string>  $branding
     */
    public static function logoDataUri(array $branding, bool $fallbackLogo = false): ?string
    {
        $logoPath = trim((string) ($branding['logo_path'] ?? ''));
        if ($logoPath === '') {
            return $fallbackLogo ? self::fallbackLogoDataUri() : null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($logoPath)) {
                return $fallbackLogo ? self::fallbackLogoDataUri() : null;
            }
            $mime = strtolower((string) ($disk->mimeType($logoPath) ?? 'image/png'));
            if ($mime === 'image/jpg') {
                $mime = 'image/jpeg';
            }
            $contents = $disk->get($logoPath);
            if ($contents === '') {
                return $fallbackLogo ? self::fallbackLogoDataUri() : null;
            }

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (Throwable $e) {
            Log::warning('report_pdf_logo_data_uri_failed', ['message' => $e->getMessage()]);

            return $fallbackLogo ? self::fallbackLogoDataUri() : null;
        }
    }

    private static function fallbackLogoDataUri(): ?string
    {
        $jpeg = public_path('icons/invoice-logo.jpg');
        if (is_file($jpeg)) {
            return self::fileDataUri($jpeg, 'image/jpeg');
        }

        return self::fileDataUri(public_path('icons/apple-touch-icon.png'), 'image/png');
    }

    private static function fileDataUri(string $path, string $mime): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
