<?php

declare(strict_types=1);

namespace App\Services;

use Smalot\PdfParser\Parser;

class DeliveryInvoicePdfExtractor
{
    /**
     * @return list<string>
     */
    public function extractInvoiceNumbers(string $filePath): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
        $text = $this->normalizeDigits($pdf->getText());

        preg_match_all('/\b[0-9][0-9\-\/]{2,30}\b/', $text, $matches);
        $tokens = $matches[0] ?? [];
        if (! is_array($tokens)) {
            return [];
        }

        $out = [];
        foreach ($tokens as $token) {
            $candidate = trim((string) $token);
            if ($candidate === '') {
                continue;
            }

            // Skip obvious date fragments and short noise.
            if (preg_match('/^\d{1,2}$/', $candidate) === 1) {
                continue;
            }
            if (preg_match('/^(19|20)\d{2}$/', $candidate) === 1) {
                continue;
            }

            $out[] = $candidate;
        }

        return array_values(array_unique($out));
    }

    private function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}

