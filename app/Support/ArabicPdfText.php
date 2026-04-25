<?php

declare(strict_types=1);

namespace App\Support;

use ArPHP\I18N\Arabic;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DomPDF draws text in LTR order; Arabic needs glyph joining + RTL handling for correct display.
 *
 * Use in every DomPDF Blade view that may show Arabic (data fields, filters, mixed-language meta).
 * Pass plain Latin/numbers through too; keep purely numeric table cells as Western digits without
 * glyphs if you need strict alignment (see report export rules).
 */
final class ArabicPdfText
{
    private static ?Arabic $arabic = null;

    /**
     * @param  int  $maxCharsPerLine  High value avoids awkward wraps in table cells (ar-php wraps long lines).
     */
    public static function glyphs(string $text, int $maxCharsPerLine = 2000): string
    {
        if ($text === '') {
            return '';
        }

        try {
            self::$arabic ??= new Arabic();

            // hindo: Arabic-Indic digits; forcertl: force RTL for bidi so words are not reversed in PDF.
            return self::$arabic->utf8Glyphs($text, $maxCharsPerLine, true, true);
        } catch (Throwable $e) {
            Log::warning('arabic_pdf_text.glyphs_failed', [
                'message' => $e->getMessage(),
            ]);

            return self::fallbackPlain($text);
        }
    }

    /**
     * Keep Western digits (0-9) while shaping Arabic glyphs.
     *
     * @param  int  $maxCharsPerLine  High value avoids awkward wraps in table cells (ar-php wraps long lines).
     */
    public static function glyphsKeepLatinDigits(string $text, int $maxCharsPerLine = 2000): string
    {
        if ($text === '') {
            return '';
        }

        try {
            self::$arabic ??= new Arabic();

            // hindo=false keeps Western digits; forcertl=true preserves Arabic word order in PDF.
            return self::$arabic->utf8Glyphs($text, $maxCharsPerLine, false, true);
        } catch (Throwable $e) {
            Log::warning('arabic_pdf_text.glyphs_keep_latin_digits_failed', [
                'message' => $e->getMessage(),
            ]);

            return self::fallbackPlain($text);
        }
    }

    private static function fallbackPlain(string $text): string
    {
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return mb_strlen($t) > 12000 ? mb_substr($t, 0, 12000).'…' : $t;
    }
}
