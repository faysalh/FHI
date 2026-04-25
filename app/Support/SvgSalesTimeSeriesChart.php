<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;

/**
 * Renders a single combined SVG line chart for DomPDF (all series in one plot, independent vertical scales).
 */
final class SvgSalesTimeSeriesChart
{
    public const SERIES_AMOUNT = 'amount';

    public const SERIES_UNITS = 'units_sold';

    public const SERIES_WEIGHT = 'weight_total';

    public const SERIES_CUSTOMERS = 'customer_count';

    public const SERIES_INVOICES = 'invoice_count';

    /**
     * @var list<string>
     */
    public const DEFAULT_SERIES_ORDER = [
        self::SERIES_AMOUNT,
        self::SERIES_UNITS,
        self::SERIES_WEIGHT,
        self::SERIES_CUSTOMERS,
        self::SERIES_INVOICES,
    ];

    /**
     * One chart: same date axis, each line scaled by its own min/max (like the on-screen Chart.js view).
     *
     * @param  list<object>  $rows
     * @param  list<string>|null  $seriesKeys
     */
    public static function render(array $rows, int $width = 900, int $unusedPanelHeight = 108, ?array $seriesKeys = null): string
    {
        unset($unusedPanelHeight);

        return self::renderCombined($rows, $width, $seriesKeys);
    }

    /**
     * @param  list<object>  $rows
     * @param  list<string>|null  $seriesKeys
     */
    public static function renderCombined(array $rows, int $width = 900, ?array $seriesKeys = null): string
    {
        if ($rows === []) {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="40"><text x="4" y="22" font-size="11" font-family="DejaVu Sans, Arial, sans-serif">No data</text></svg>';
        }

        $keys = self::normalizeSeriesKeys($seriesKeys);
        if ($keys === []) {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="40"><text x="4" y="22" font-size="11" font-family="DejaVu Sans, Arial, sans-serif">No series selected</text></svg>';
        }

        $labels = [];
        foreach ($rows as $r) {
            $labels[] = self::formatDate($r);
        }

        $n = count($labels);
        $plotH = 290.0;
        $padL = 56.0;
        $padR = 56.0;
        $pw = $width - $padL - $padR;
        $legendRowH = 15.0;
        $perLegendRow = 3;
        $legendRows = (int) max(1, ceil(count($keys) / $perLegendRow));
        $legendBlockH = 8.0 + $legendRows * $legendRowH;
        $plotY0 = $legendBlockH;
        $xAxisH = 36.0;
        $totalH = (int) round($plotY0 + $plotH + $xAxisH + 8);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$totalH.'" viewBox="0 0 '.$width.' '.$totalH.'">';
        $svg .= '<g font-family="DejaVu Sans, Arial, sans-serif">';

        // Legend: color + label + value range (each line uses its own scale in the plot, same as the live chart).
        $colW = ($width - 24) / $perLegendRow;
        foreach ($keys as $idx => $key) {
            $meta = self::seriesMeta($key);
            $vals = [];
            foreach ($rows as $r) {
                $vals[] = self::valueForKey($r, $key);
            }
            $vmin = min($vals);
            $vmax = max($vals);
            $rangeLabel = self::fmtNum($vmin).'–'.self::fmtNum($vmax);
            $color = $meta['color'];
            $title = $meta['title'];
            $row = intdiv($idx, $perLegendRow);
            $col = $idx % $perLegendRow;
            $lx = 12.0 + $col * $colW;
            $ly = 12.0 + $row * $legendRowH;
            $svg .= '<rect x="'.$lx.'" y="'.($ly - 8).'" width="9" height="9" fill="'.$color.'"/>';
            $svg .= '<text x="'.($lx + 13).'" y="'.$ly.'" font-size="8.5" fill="#0f172a">'.htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8').' ('.htmlspecialchars($rangeLabel, ENT_XML1 | ENT_QUOTES, 'UTF-8').')</text>';
        }

        // Plot frame + light grid
        $svg .= '<rect x="'.$padL.'" y="'.$plotY0.'" width="'.$pw.'" height="'.$plotH.'" fill="#f8fafc" stroke="#cbd5e1" stroke-width="0.8"/>';
        for ($g = 1; $g <= 3; $g++) {
            $gy = $plotY0 + ($plotH * $g / 4);
            $svg .= '<line x1="'.$padL.'" y1="'.$gy.'" x2="'.($padL + $pw).'" y2="'.$gy.'" stroke="#e2e8f0" stroke-width="0.5"/>';
        }

        // Each series: own vertical mapping into the same plot box
        foreach ($keys as $key) {
            $meta = self::seriesMeta($key);
            $color = $meta['color'];
            $vals = [];
            foreach ($rows as $r) {
                $vals[] = self::valueForKey($r, $key);
            }
            $smin = min($vals);
            $smax = max($vals);
            if ($smax <= $smin) {
                $smax = $smin + 1e-9;
            }

            $points = [];
            for ($i = 0; $i < $n; $i++) {
                $xi = $n <= 1 ? $padL + $pw / 2 : $padL + ($pw * $i / max(1, $n - 1));
                $vi = $vals[$i];
                $yi = $plotY0 + $plotH - (($vi - $smin) / ($smax - $smin)) * $plotH;
                $points[] = round($xi, 2).','.round($yi, 2);
            }
            if ($n === 1 && isset($points[0])) {
                $parts = explode(',', $points[0]);
                if (count($parts) === 2) {
                    $points[] = (round((float) $parts[0] + 2, 2)).','.$parts[1];
                }
            }

            $poly = implode(' ', $points);
            $svg .= '<polyline fill="none" stroke="'.$color.'" stroke-width="2.25" stroke-linejoin="round" stroke-linecap="round" points="'.$poly.'"/>';
            $svg .= self::svgLineSegmentsFromPoints($points, $color, '2.25');
            foreach ($points as $pt) {
                $xy = explode(',', $pt);
                if (count($xy) === 2) {
                    $svg .= '<circle cx="'.$xy[0].'" cy="'.$xy[1].'" r="2.5" fill="'.$color.'" stroke="#ffffff" stroke-width="0.6"/>';
                }
            }
        }

        // X axis labels
        $axisY = $plotY0 + $plotH + 14;
        $firstLabel = $labels[0] ?? '';
        $lastLabel = $labels[$n - 1] ?? $firstLabel;
        $svg .= '<text x="'.$padL.'" y="'.$axisY.'" font-size="8" fill="#64748b">'.htmlspecialchars($firstLabel, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</text>';
        if ($n > 2) {
            $mid = (int) floor(($n - 1) / 2);
            $xm = $padL + ($pw * $mid / max(1, $n - 1));
            $svg .= '<text x="'.$xm.'" y="'.$axisY.'" font-size="8" fill="#64748b" text-anchor="middle">'.htmlspecialchars($labels[$mid], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</text>';
        }
        if ($n > 1) {
            $svg .= '<text x="'.($padL + $pw).'" y="'.$axisY.'" font-size="8" fill="#64748b" text-anchor="end">'.htmlspecialchars($lastLabel, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</text>';
        }

        $svg .= '<text x="'.$padL.'" y="'.($plotY0 + $plotH + 28).'" font-size="8" fill="#64748b">Date (one point per day)</text>';
        $svg .= '</g></svg>';

        return $svg;
    }

    /**
     * SVG suitable for DomPDF: embed via &lt;img src="..."&gt;.
     */
    public static function toDataUriForPdf(string $svgXml): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($svgXml)) ?? $svgXml;

        return 'data:image/svg+xml;base64,'.base64_encode($clean);
    }

    /**
     * @param  list<string>|null  $seriesKeys
     * @return list<string>
     */
    public static function normalizeSeriesKeys(?array $seriesKeys): array
    {
        $allowed = array_flip(self::DEFAULT_SERIES_ORDER);
        if ($seriesKeys === null || $seriesKeys === []) {
            return self::DEFAULT_SERIES_ORDER;
        }

        $out = [];
        foreach ($seriesKeys as $k) {
            if ($k !== '' && isset($allowed[$k])) {
                $out[] = $k;
            }
        }

        $out = array_values(array_unique($out));

        return $out === [] ? self::DEFAULT_SERIES_ORDER : $out;
    }

    /**
     * @return array{title: string, color: string}
     */
    private static function seriesMeta(string $key): array
    {
        return match ($key) {
            self::SERIES_AMOUNT => ['title' => 'Amount (IQD)', 'color' => '#0ea5e9'],
            self::SERIES_UNITS => ['title' => 'Quantity (pcs)', 'color' => '#16a34a'],
            self::SERIES_WEIGHT => ['title' => 'Weight (kg)', 'color' => '#ea580c'],
            self::SERIES_CUSTOMERS => ['title' => 'Customers', 'color' => '#7c3aed'],
            self::SERIES_INVOICES => ['title' => 'Invoices', 'color' => '#db2777'],
            default => ['title' => $key, 'color' => '#64748b'],
        };
    }

    private static function valueForKey(object $r, string $key): float
    {
        return match ($key) {
            self::SERIES_AMOUNT => (float) (self::prop($r, 'amount') ?? 0),
            self::SERIES_UNITS => (float) (self::prop($r, 'units_sold') ?? 0),
            self::SERIES_WEIGHT => (float) (self::prop($r, 'weight_total') ?? 0),
            self::SERIES_CUSTOMERS => (float) (self::prop($r, 'customer_count') ?? 0),
            self::SERIES_INVOICES => (float) (self::prop($r, 'invoice_count') ?? 0),
            default => 0.0,
        };
    }

    private static function prop(object $r, string $name): mixed
    {
        if (isset($r->{$name})) {
            return $r->{$name};
        }

        foreach (get_object_vars($r) as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $points  "x,y" pairs in pixel space
     */
    private static function svgLineSegmentsFromPoints(array $points, string $color, string $strokeWidth = '2.5'): string
    {
        $out = '';
        $n = count($points);
        for ($i = 0; $i < $n - 1; $i++) {
            $a = explode(',', $points[$i]);
            $b = explode(',', $points[$i + 1]);
            if (count($a) !== 2 || count($b) !== 2) {
                continue;
            }
            $out .= '<line x1="'.htmlspecialchars($a[0], ENT_XML1 | ENT_QUOTES, 'UTF-8').'" y1="'.htmlspecialchars($a[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'" x2="'.htmlspecialchars($b[0], ENT_XML1 | ENT_QUOTES, 'UTF-8').'" y2="'.htmlspecialchars($b[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'" stroke="'.htmlspecialchars($color, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" stroke-width="'.htmlspecialchars($strokeWidth, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" stroke-linecap="round"/>';
        }

        return $out;
    }

    private static function fmtNum(float $v): string
    {
        if (abs($v - round($v)) < 1e-6) {
            return (string) (int) round($v);
        }

        return number_format($v, 2, '.', ',');
    }

    private static function formatDate(object $r): string
    {
        $d = self::prop($r, 'sale_date');
        if ($d instanceof DateTimeInterface) {
            return $d->format('Y-m-d');
        }

        $s = (string) ($d ?? '');

        return strlen($s) >= 10 ? substr($s, 0, 10) : $s;
    }
}
