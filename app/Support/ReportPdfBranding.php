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
            return self::fallbackLogoDataUri();
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($logoPath)) {
                return self::fallbackLogoDataUri();
            }
            $mime = strtolower((string) ($disk->mimeType($logoPath) ?? 'image/png'));
            $contents = $disk->get($logoPath);
            if ($contents === '') {
                return self::fallbackLogoDataUri();
            }
            if (! self::dompdfCanEmbed($mime)) {
                return self::fallbackLogoDataUri();
            }

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (Throwable $e) {
            Log::warning('report_pdf_logo_data_uri_failed', ['message' => $e->getMessage()]);

            return self::fallbackLogoDataUri();
        }
    }

    /**
     * DomPDF embeds JPEG without the GD extension. PNG and GIF need GD, which
     * is not available on every PHP install used to export invoices.
     */
    private static function dompdfCanEmbed(string $mime): bool
    {
        if (in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            return true;
        }

        return function_exists('imagecreatefrompng')
            && in_array($mime, ['image/png', 'image/gif'], true);
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
