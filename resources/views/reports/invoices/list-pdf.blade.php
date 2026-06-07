@php
    use App\Support\ArabicPdfText as Ar;

    $meta = 'Period: '.$dateFrom.' — '.$dateTo;
    $meta .= ($cities ?? []) !== [] ? ' | Cities: '.implode('; ', $cities) : ' | Cities: all';
    $meta .= trim((string) ($store ?? '')) !== '' ? ' | Store: '.$store : '';
    $meta .= trim((string) ($salesmanId ?? '')) !== '' ? ' | Salesman filter set' : '';
    $meta .= trim((string) ($searchText ?? '')) !== '' ? ' | Search: '.$searchText : '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoices report</title>
    <style>
        @include('reports.partials.pdf-styles')
        th { font-size: 8px; }
    </style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', [
        'title' => 'Invoices report',
        'meta' => $meta,
        'glyphsKeepLatinDigits' => true,
    ])
    <table>
        <thead>
        <tr>
            <th>{{ Ar::glyphsKeepLatinDigits('#') }}</th>
            <th>{{ Ar::glyphs('Invoice no') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Date') }}</th>
            <th>{{ Ar::glyphs('Client') }}</th>
            <th>{{ Ar::glyphs('City') }}</th>
            <th>{{ Ar::glyphs('Salesman') }}</th>
            <th>{{ Ar::glyphs('Store') }}</th>
            <th class="num">{{ Ar::glyphsKeepLatinDigits('Qty') }}</th>
            <th class="num">{{ Ar::glyphsKeepLatinDigits('Amount') }}</th>
            <th class="num">{{ Ar::glyphsKeepLatinDigits('Due') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->invoice_no ?? '')) }}</td>
                <td>{{ (string) ($row->invoice_date ?? '') }}</td>
                <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->city_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->salesman_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->store_name ?? '')) }}</td>
                <td class="num">{{ display_number((float) ($row->quantity_total ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->invoice_amount ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->client_due_amount ?? 0)) }}</td>
            </tr>
        @endforeach
        </tbody>
        @if (!empty($grandTotals))
            <tfoot>
            <tr>
                <td colspan="7">{{ Ar::glyphsKeepLatinDigits('Grand total (all matching filters)') }}</td>
                <td class="num">{{ display_number((float) ($grandTotals->quantity_total ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($grandTotals->invoice_amount ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($grandTotals->client_due_amount ?? 0)) }}</td>
            </tr>
            </tfoot>
        @endif
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
