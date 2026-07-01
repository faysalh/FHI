@php use App\Support\ArabicPdfText as Ar; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transfers {{ $sheetDate }}</title>
    <style>
        @include('reports.partials.pdf-styles')
        body { direction: ltr; unicode-bidi: normal; }
        table { direction: ltr; table-layout: auto; width: 100%; }
        th, td { word-wrap: break-word; padding: 4px 6px; }
        .num { text-align: right; }
    </style>
</head>
<body>
@include('reports.partials.pdf-branding-header')
@include('reports.partials.pdf-title-block', [
    'title' => 'Incoming transfers',
    'meta' => 'Date: '.$sheetDate,
])

<table>
    <thead>
    <tr>
        <th>#</th>
        <th class="num">Amount</th>
        <th>Currency</th>
        <th class="num">USD rate</th>
        <th class="num">IQD equivalent</th>
        <th>From</th>
        <th>Note</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($rows as $index => $row)
        @php
            $currency = strtoupper((string) ($row->currency ?? 'IQD'));
            $amount = (float) ($row->amount ?? 0);
            $iqdEq = $currency === 'USD' ? $amount * (float) ($row->usd_rate ?? 0) : $amount;
        @endphp
        <tr>
            <td>{{ $index + 1 }}</td>
            <td class="num">{{ display_number($amount) }}</td>
            <td>{{ $currency }}</td>
            <td class="num">{{ $currency === 'USD' ? display_number((float) ($row->usd_rate ?? 0)) : '—' }}</td>
            <td class="num">{{ display_number($iqdEq) }}</td>
            <td>{{ Ar::glyphs((string) ($row->person_name ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->note ?? '')) }}</td>
        </tr>
    @empty
        <tr><td colspan="7">No transfers.</td></tr>
    @endforelse
    </tbody>
    @if (count($rows) > 0)
        <tfoot>
        <tr>
            <th colspan="4">Day total (IQD equivalent)</th>
            <th class="num">{{ display_number($iqdTotal) }}</th>
            <th colspan="2"></th>
        </tr>
        </tfoot>
    @endif
</table>
@include('reports.partials.pdf-footer')
</body>
</html>
