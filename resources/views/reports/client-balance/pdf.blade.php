@php
    use App\Support\ArabicPdfText as Ar;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ Ar::glyphs('Client balance') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; direction: ltr; unicode-bidi: normal; }
        h1 { font-size: 16px; margin: 0 0 8px; }
        .meta { margin: 0 0 12px; color: #444; }
        table { width: 100%; border-collapse: collapse; direction: ltr; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; vertical-align: top; word-wrap: break-word; }
        th { background: #f3f4f6; text-align: left; }
        .num { text-align: right; white-space: nowrap; }
        tfoot th { background: #eef2ff; }
    </style>
</head>
<body>
@include('reports.partials.pdf-branding-header')
<h1>{{ Ar::glyphs('Client balance') }}</h1>
<p class="meta">
    {{ Ar::glyphs('Salesman: '.($salesmanName ?? '—')) }}
    · {{ Ar::glyphs('Year: '.($yearName ?? '—')) }}
    · {{ Ar::glyphs('Currency: '.($currencyLabel ?? '—')) }}
</p>
<table>
    <thead>
    <tr>
        <th>{{ Ar::glyphs('Client code') }}</th>
        <th>{{ Ar::glyphs('Client name') }}</th>
        <th>{{ Ar::glyphs('Salesman') }}</th>
        <th class="num">{{ Ar::glyphs('Balance') }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse (($rows ?? []) as $row)
        <tr>
            <td>{{ Ar::glyphs((string) ($row->client_code ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->salesman_name ?? '')) }}</td>
            <td class="num">{{ display_number((float) ($row->balance ?? 0)) }}</td>
        </tr>
    @empty
        <tr><td colspan="4">{{ Ar::glyphs('No clients found for this salesman.') }}</td></tr>
    @endforelse
    </tbody>
    <tfoot>
    <tr>
        <th colspan="3">{{ Ar::glyphs('Total') }}</th>
        <th class="num">{{ display_number($grandTotal ?? 0) }}</th>
    </tr>
    </tfoot>
</table>
</body>
</html>
