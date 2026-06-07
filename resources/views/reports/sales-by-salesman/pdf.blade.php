@php
    use App\Support\ArabicPdfText as Ar;

    $salesmanDisplay = trim((string) ($salesmanName ?? ''));
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Sales by salesman</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')

    <div class="pdf-report-head">
        <h1 class="pdf-title">{{ Ar::glyphs('Sales by salesman') }}</h1>
        <div class="pdf-meta">
            <div class="pdf-meta-row">{{ Ar::glyphsKeepLatinDigits('Period: '.$dateFrom.' — '.$dateTo) }}</div>
            <table class="pdf-meta-table">
                <tr>
                    <td class="pdf-meta-label">{{ Ar::glyphsKeepLatinDigits('Salesman:') }}</td>
                    <td>@if ($salesmanDisplay !== ''){{ Ar::glyphs($salesmanDisplay) }}@else{{ Ar::glyphsKeepLatinDigits('—') }}@endif</td>
                </tr>
            </table>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>{{ Ar::glyphs('Client name') }}</th>
            <th>{{ Ar::glyphs('Client price group') }}</th>
            <th>{{ Ar::glyphs('Number of invoices') }}</th>
            <th>{{ Ar::glyphs('Quantity of sales') }}</th>
            <th>{{ Ar::glyphs('Amount of sales') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->client_price_group ?? '')) }}</td>
                <td class="num">{{ display_number((float) ($row->invoice_count ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->quantity_sold ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->amount ?? 0)) }}</td>
            </tr>
        @endforeach
        </tbody>
        @if (!empty($grandTotals))
            <tfoot>
            <tr>
                <td colspan="3">{{ Ar::glyphs('Total (all clients)') }}</td>
                <td class="num">{{ display_number((float) ($grandTotals->sum_invoice_count ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($grandTotals->sum_quantity_sold ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($grandTotals->sum_amount ?? 0)) }}</td>
            </tr>
            </tfoot>
        @endif
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
