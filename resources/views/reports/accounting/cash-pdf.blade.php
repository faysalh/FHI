@php use App\Support\ArabicPdfText as Ar; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cash sheet {{ $sheetDate }}</title>
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
    'title' => 'Money tracker — cash sheet',
    'meta' => 'Date: '.$sheetDate,
])

<table style="margin-bottom:12px;">
    <tr><th>Received (IQD)</th><td class="num">{{ display_number($bundle['sheet'] !== null ? (float) ($bundle['sheet']->opening_amount ?? 0) : 0.0) }}</td></tr>
    <tr><th>Spent (IQD)</th><td class="num">{{ display_number((float) ($bundle['spent'] ?? 0)) }}</td></tr>
    <tr><th>Remaining (IQD)</th><td class="num">{{ display_number((float) ($bundle['remaining'] ?? 0)) }}</td></tr>
</table>

<table>
    <thead>
    <tr>
        <th>#</th>
        <th class="num">Amount (IQD)</th>
        <th>To whom</th>
        <th>Note</th>
    </tr>
    </thead>
    <tbody>
    @forelse (($bundle['rows'] ?? []) as $index => $row)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td class="num">{{ display_number((float) ($row->amount ?? 0)) }}</td>
            <td>{{ Ar::glyphs((string) ($row->paid_to ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->note ?? '')) }}</td>
        </tr>
    @empty
        <tr><td colspan="4">No spend rows.</td></tr>
    @endforelse
    </tbody>
</table>
@include('reports.partials.pdf-footer')
</body>
</html>
