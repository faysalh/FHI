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

        $fromDeliveryReport = $this->extractFromDeliveryReportTable($text);
        if ($fromDeliveryReport !== []) {
            return $fromDeliveryReport;
        }

        return $this->extractGeneric($this->stripDeliveryReportHeader($text));
    }

    /**
     * Invoice delivery PDFs list invoices in a table:
     *   # Invoice Number Pickup Time Notes
     *   1 53267 10:03:06
     *
     * @return list<string>
     */
    private function extractFromDeliveryReportTable(string $text): array
    {
        if (! $this->looksLikeDeliveryReport($text)) {
            return [];
        }

        preg_match_all(
            '/^\s*\d+\s+(\d+)\s+\d{1,2}:\d{2}:\d{2}/m',
            $text,
            $matches
        );

        $numbers = [];
        foreach ($matches[1] ?? [] as $token) {
            $candidate = trim((string) $token);
            if ($candidate !== '') {
                $numbers[] = $candidate;
            }
        }

        return array_values(array_unique($numbers));
    }

    private function looksLikeDeliveryReport(string $text): bool
    {
        if (preg_match('/invoice\s+delivery\s+report/i', $text) === 1) {
            return true;
        }

        return preg_match('/#\s*invoice\s+number\s+pickup\s+time/i', $text) === 1;
    }

    /**
     * Drop report header/footer metadata before generic number scanning.
     */
    private function stripDeliveryReportHeader(string $text): string
    {
        if (preg_match('/^\s*invoices\s*$/mi', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $match[0][1] ?? null;
            if (is_int($offset)) {
                return substr($text, $offset);
            }
        }

        if (preg_match('/#\s*invoice\s+number\s+pickup\s+time/i', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $match[0][1] ?? null;
            if (is_int($offset)) {
                return substr($text, $offset);
            }
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $filtered = [];
        foreach ($lines as $line) {
            if ($this->isDeliveryReportHeaderLine($line)) {
                continue;
            }
            $filtered[] = $line;
        }

        return implode("\n", $filtered);
    }

    private function isDeliveryReportHeaderLine(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }

        return preg_match(
            '/^(invoice delivery report|branch\s*:|report\s*#|report date|driver|car model|plate number|invoice count|generated on|--\s*\d+\s+of\s+\d+\s+--)/i',
            $trimmed
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function extractGeneric(string $text): array
    {
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
