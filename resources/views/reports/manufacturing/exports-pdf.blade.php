@php use App\Support\ArabicPdfText as Ar; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manufacturing exports {{ $dateFrom }} – {{ $dateTo }}</title>
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
    'title' => 'Manufacturing exports',
    'meta' => 'Period: '.$dateFrom.' – '.$dateTo,
])

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Date</th>
        <th>Item</th>
        <th>Unit</th>
        <th class="num">Qty</th>
        <th>Note</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($rows as $index => $row)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ $row->export_date }}</td>
            <td>{{ Ar::glyphs((string) ($row->item_name ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->item_unit ?? '')) }}</td>
            <td class="num">{{ display_number($row->quantity) }}</td>
            <td>{{ Ar::glyphs((string) ($row->note ?? '')) }}</td>
        </tr>
    @empty
        <tr><td colspan="6">No exports in this period.</td></tr>
    @endforelse
    </tbody>
</table>
@include('reports.partials.pdf-footer')
</body>
</html>
