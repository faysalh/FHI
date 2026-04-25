@php
    use App\Support\ArabicPdfText as Ar;
    use App\Support\SvgSalesTimeSeriesChart;

    $pdfMeta = 'Period: '.$dateFrom.' — '.$dateTo;
    if (! empty($citiesLabel)) {
        $pdfMeta .= ' | Cities: '.$citiesLabel;
    } else {
        $pdfMeta .= ' | Cities: all';
    }
    /** @var list<string> $chartShow */
    $chartShow = $chartShow ?? SvgSalesTimeSeriesChart::DEFAULT_SERIES_ORDER;
    $svgXml = SvgSalesTimeSeriesChart::renderCombined($rows, 900, $chartShow);
    $chartImageSrc = SvgSalesTimeSeriesChart::toDataUriForPdf($svgXml);
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Cities sales chart</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; margin: 12px; direction: ltr; unicode-bidi: normal; }
        h1 { font-size: 15px; margin: 0 0 8px 0; }
        .meta { font-size: 9px; color: #444; margin-bottom: 14px; }
        .chart-box { margin-top: 8px; overflow: visible; }
        .note { font-size: 8px; color: #64748b; margin-top: 10px; max-width: 900px; line-height: 1.4; }
    </style>
</head>
<body>
    <h1>{{ Ar::glyphs('Cities sales — chart (daily)') }}</h1>
    <div class="meta">{{ Ar::glyphs($pdfMeta) }}</div>
    <div class="chart-box">
        {{-- DomPDF often omits strokes on inline SVG; data-URI image uses the SVG raster pipeline reliably. --}}
        <img src="{{ $chartImageSrc }}" width="900" alt="Sales chart" style="display:block;max-width:100%;height:auto;"/>
    </div>
    <p class="note">{{ Ar::glyphs('Single chart: all selected series share the same time axis; each line uses its own scale (range shown in the legend), matching the on-screen chart. Units: amount in IQD, quantity in pcs, and weight in kg. Customers = distinct accounts with sales that day; invoices = distinct sales documents that day.') }}</p>
</body>
</html>
