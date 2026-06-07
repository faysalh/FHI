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
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', ['title' => 'Cities sales — chart (daily)', 'meta' => $pdfMeta])
    <div class="chart-box">
        {{-- DomPDF often omits strokes on inline SVG; data-URI image uses the SVG raster pipeline reliably. --}}
        <img src="{{ $chartImageSrc }}" width="900" alt="Sales chart" style="display:block;max-width:100%;height:auto;"/>
    </div>
    <p class="pdf-note">{{ Ar::glyphs('Single chart: all selected series share the same time axis; each line uses its own scale (range shown in the legend), matching the on-screen chart. Units: amount in IQD, quantity in pcs, and weight in kg. Customers = distinct accounts with sales that day; invoices = distinct sales documents that day.') }}</p>
    @include('reports.partials.pdf-footer')
</body>
</html>
