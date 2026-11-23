<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class DeliveryInvoiceSpreadsheetExtractor
{
    /**
     * @return list<string>
     */
    public function extractInvoiceNumbersFromUpload(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new \InvalidArgumentException('The uploaded spreadsheet could not be read. Check PHP upload settings (upload_max_filesize, post_max_size, upload_tmp_dir).');
        }

        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            $path = $file->getPathname();
        }

        if ($path === '' || ! is_readable($path)) {
            throw new \InvalidArgumentException('The uploaded spreadsheet could not be read.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'csv') {
            return $this->extractFromCsv($path);
        }

        if ($extension === 'xlsx' && ! class_exists(\ZipArchive::class)) {
            if (PHP_OS_FAMILY === 'Windows') {
                return $this->extractFromXlsxViaWindowsUnzip($path);
            }

            throw new \InvalidArgumentException(
                'Excel (.xlsx) requires the PHP zip extension. Save the file as CSV, or enable extension=zip in php.ini and restart the web server.'
            );
        }

        return $this->extractFromSpreadsheetPhpSpreadsheet($path);
    }

    /**
     * @return list<string>
     */
    private function extractFromCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('The uploaded CSV could not be read.');
        }

        $numbers = [];
        $rowIndex = 0;
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowIndex++;
                $value = $this->firstNonEmptyCell($row);
                if ($value === '') {
                    continue;
                }
                if ($rowIndex === 1 && $this->looksLikeHeader($value)) {
                    continue;
                }
                $numbers[] = $value;
            }
        } finally {
            fclose($handle);
        }

        return $this->uniqueNumbers($numbers);
    }

    /**
     * Read .xlsx on Windows when php_zip is missing (Expand-Archive + sheet XML).
     *
     * @return list<string>
     */
    private function extractFromXlsxViaWindowsUnzip(string $path): array
    {
        $tempBase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'delivery_batch_xlsx_'.bin2hex(random_bytes(8));
        $zipPath = $tempBase.'.zip';
        $extractDir = $tempBase.'_out';

        try {
            if (! @mkdir($extractDir) && ! is_dir($extractDir)) {
                throw new RuntimeException('Could not create a temp folder for the Excel file.');
            }
            if (! @copy($path, $zipPath)) {
                throw new RuntimeException('Could not read the uploaded Excel file.');
            }

            $command = 'powershell -NoProfile -ExecutionPolicy Bypass -Command '
                .'"Expand-Archive -LiteralPath '.escapeshellarg($zipPath)
                .' -DestinationPath '.escapeshellarg($extractDir).' -Force"';
            $exitCode = 1;
            @exec($command, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException('Could not unpack the Excel file. Save it as CSV and upload again.');
            }

            $sheetPath = $extractDir.DIRECTORY_SEPARATOR.'xl'.DIRECTORY_SEPARATOR.'worksheets'.DIRECTORY_SEPARATOR.'sheet1.xml';
            if (! is_readable($sheetPath)) {
                throw new RuntimeException('The Excel file has no readable worksheet. Use a simple list in column A or save as CSV.');
            }

            $sharedPath = $extractDir.DIRECTORY_SEPARATOR.'xl'.DIRECTORY_SEPARATOR.'sharedStrings.xml';
            $sharedStrings = is_readable($sharedPath)
                ? $this->parseXlsxSharedStrings($sharedPath)
                : [];

            return $this->parseXlsxSheetFirstColumn($sheetPath, $sharedStrings);
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            $this->deleteDirectory($extractDir);
        }
    }

    /**
     * @return list<string>
     */
    private function parseXlsxSharedStrings(string $sharedPath): array
    {
        $xml = @simplexml_load_file($sharedPath);
        if ($xml === false) {
            return [];
        }

        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $out = [];
        foreach ($xml->xpath('//m:si') ?: [] as $si) {
            $si->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = $si->xpath('.//m:t') ?: [];
            $text = '';
            foreach ($parts as $part) {
                $text .= (string) $part;
            }
            $out[] = $text;
        }

        return $out;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<string>
     */
    private function parseXlsxSheetFirstColumn(string $sheetPath, array $sharedStrings): array
    {
        $xml = @simplexml_load_file($sheetPath);
        if ($xml === false) {
            throw new RuntimeException('Could not read the Excel worksheet.');
        }

        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $numbers = [];
        $rowIndex = 0;

        foreach ($xml->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $rowIndex++;
            $row->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $value = '';
            foreach ($row->xpath('./m:c') ?: [] as $cell) {
                $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $ref = (string) ($cell['r'] ?? '');
                if ($ref !== '' && ! preg_match('/^A\d+$/i', $ref)) {
                    continue;
                }

                $type = (string) ($cell['t'] ?? '');
                if ($type === 'inlineStr') {
                    $parts = $cell->xpath('.//m:t') ?: [];
                    $value = '';
                    foreach ($parts as $part) {
                        $value .= (string) $part;
                    }
                } elseif ($type === 's') {
                    $raw = (string) ($cell->xpath('./m:v')[0] ?? '');
                    $value = ($raw !== '' && ctype_digit($raw))
                        ? ($sharedStrings[(int) $raw] ?? '')
                        : '';
                } else {
                    $value = (string) ($cell->xpath('./m:v')[0] ?? '');
                }
                break;
            }

            $value = trim($this->normalizeDigits($value));
            if ($value === '') {
                continue;
            }
            if ($rowIndex === 1 && $this->looksLikeHeader($value)) {
                continue;
            }
            $numbers[] = $value;
        }

        return $this->uniqueNumbers($numbers);
    }

    /**
     * @return list<string>
     */
    private function extractFromSpreadsheetPhpSpreadsheet(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            if (! class_exists(\ZipArchive::class) && str_contains($e->getMessage(), 'ZipArchive')) {
                throw new \InvalidArgumentException(
                    'Excel requires the PHP zip extension. Save the file as CSV, or enable extension=zip in php.ini and restart the web server.',
                    0,
                    $e
                );
            }
            throw $e;
        }

        $sheet = $spreadsheet->getActiveSheet();
        $numbers = [];
        $rowIndex = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $rowIndex++;
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = (string) $cell->getFormattedValue();
            }

            $value = $this->firstNonEmptyCell($cells);
            if ($value === '') {
                continue;
            }
            if ($rowIndex === 1 && $this->looksLikeHeader($value)) {
                continue;
            }
            $numbers[] = $value;
        }

        return $this->uniqueNumbers($numbers);
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function firstNonEmptyCell(array $cells): string
    {
        foreach ($cells as $cell) {
            $value = trim($this->normalizeDigits((string) $cell));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function looksLikeHeader(string $value): bool
    {
        return preg_match('/invoice\s*(number|no|#)?/i', $value) === 1
            || preg_match('/^(رقم|فاتورة)/u', $value) === 1;
    }

    /**
     * @param  list<string>  $numbers
     * @return list<string>
     */
    private function uniqueNumbers(array $numbers): array
    {
        $out = [];
        foreach ($numbers as $number) {
            $number = trim($number);
            if ($number !== '') {
                $out[] = $number;
            }
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

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
