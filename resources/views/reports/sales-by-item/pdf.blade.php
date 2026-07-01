@php
    use App\Support\ArabicPdfText as Ar;
    use App\Support\SalesItemReportMetrics;

    $salesmanDisplay = trim((string) ($salesmanName ?? ''));
    $activePriceTiers = $priceTiers ?? [];
    $activeMetrics = $activeMetrics ?? [];
    $showUnknownColumn = (bool) ($showUnknownColumn ?? true);
    $metricGroups = count($activePriceTiers) + ($showUnknownColumn ? 1 : 0) + 1;
    $colsPerGroup = count($activeMetrics);
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Sales by item</title>
    <style>@include('reports.partials.pdf-styles')</style>
    <style>
        body { font-size: 9px; }
        table { direction: ltr; table-layout: fixed; width: 100%; word-wrap: break-word; }
        th, td { padding: 3px 4px; font-size: 8px; }
        .category-col { width: 14%; }
    </style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')

    <div class="pdf-report-head">
        <h1 class="pdf-title">{{ Ar::glyphs('Sales by item') }}</h1>
        <div class="pdf-meta">
            <div class="pdf-meta-row">{{ Ar::glyphsKeepLatinDigits('Period: '.$dateFrom.' — '.$dateTo) }}</div>
            <table class="pdf-meta-table">
                <tr>
                    <td class="pdf-meta-label">{{ Ar::glyphsKeepLatinDigits('Salesman:') }}</td>
                    <td>@if ($salesmanDisplay !== ''){{ Ar::glyphs($salesmanDisplay) }}@else{{ Ar::glyphsKeepLatinDigits('—') }}@endif</td>
                </tr>
                @if (!empty($storage))
                <tr>
                    <td class="pdf-meta-label">{{ Ar::glyphsKeepLatinDigits('Storage:') }}</td>
                    <td>{{ Ar::glyphs((string) $storage) }}</td>
                </tr>
                @endif
                @if (!empty($priceTierFilterLabels))
                <tr>
                    <td class="pdf-meta-label">{{ Ar::glyphsKeepLatinDigits('Price groups:') }}</td>
                    <td>{{ Ar::glyphs(implode(', ', $priceTierFilterLabels)) }}</td>
                </tr>
                @endif
                @if (!empty($metricFilterLabels))
                <tr>
                    <td class="pdf-meta-label">{{ Ar::glyphsKeepLatinDigits('Columns:') }}</td>
                    <td>{{ Ar::glyphs(implode(', ', $metricFilterLabels)) }}</td>
                </tr>
                @endif
            </table>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th class="category-col" rowspan="2">{{ Ar::glyphs('Category') }}</th>
            @foreach ($activePriceTiers as $tier)
                <th colspan="{{ $colsPerGroup }}" class="num">{{ Ar::glyphs('P'.$tier['tier'].' '.$tier['label']) }}</th>
            @endforeach
            @if ($showUnknownColumn)
                <th colspan="{{ $colsPerGroup }}" class="num">{{ Ar::glyphs('Unknown') }}</th>
            @endif
            <th colspan="{{ $colsPerGroup }}" class="num">{{ Ar::glyphs('Total') }}</th>
        </tr>
        <tr>
            @for ($i = 0; $i < $metricGroups; $i++)
                @foreach ($activeMetrics as $metric)
                    <th class="num">{{ Ar::glyphs($metric['label']) }}</th>
                @endforeach
            @endfor
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ Ar::glyphs((string) ($row->category_name ?? '')) }}</td>
                @foreach ($activePriceTiers as $tier)
                    @foreach ($activeMetrics as $metric)
                        @php $field = SalesItemReportMetrics::fieldKey('tier', $metric['suffix'], $tier['tier']); @endphp
                        <td class="num">{{ display_number((float) ($row->{$field} ?? 0)) }}</td>
                    @endforeach
                @endforeach
                @if ($showUnknownColumn)
                    @foreach ($activeMetrics as $metric)
                        @php $field = SalesItemReportMetrics::fieldKey('unmatched', $metric['suffix']); @endphp
                        <td class="num">{{ display_number((float) ($row->{$field} ?? 0)) }}</td>
                    @endforeach
                @endif
                @foreach ($activeMetrics as $metric)
                    @php $field = SalesItemReportMetrics::fieldKey('total', $metric['suffix']); @endphp
                    <td class="num">{{ display_number((float) ($row->{$field} ?? 0)) }}</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
        @if (!empty($grandTotals))
            <tfoot>
            <tr>
                <th>{{ Ar::glyphs('Total') }}</th>
                @foreach ($activePriceTiers as $tier)
                    @foreach ($activeMetrics as $metric)
                        @php $field = SalesItemReportMetrics::fieldKey('tier', $metric['suffix'], $tier['tier']); @endphp
                        <td class="num">{{ display_number((float) ($grandTotals->{$field} ?? 0)) }}</td>
                    @endforeach
                @endforeach
                @if ($showUnknownColumn)
                    @foreach ($activeMetrics as $metric)
                        @php $field = SalesItemReportMetrics::fieldKey('unmatched', $metric['suffix']); @endphp
                        <td class="num">{{ display_number((float) ($grandTotals->{$field} ?? 0)) }}</td>
                    @endforeach
                @endif
                @foreach ($activeMetrics as $metric)
                    @php $field = SalesItemReportMetrics::fieldKey('total', $metric['suffix']); @endphp
                    <td class="num">{{ display_number((float) ($grandTotals->{$field} ?? 0)) }}</td>
                @endforeach
            </tr>
            </tfoot>
        @endif
    </table>

    @include('reports.partials.pdf-footer')
</body>
</html>
