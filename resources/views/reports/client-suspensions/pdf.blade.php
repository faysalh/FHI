@php
    use App\Support\ArabicPdfText as Ar;

    $title = 'Client suspensions';
    $meta = $search !== '' ? 'Search: '.$search : '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
    <style>
        @include('reports.partials.pdf-styles')
        body { direction: ltr; unicode-bidi: normal; }
        table { direction: ltr; }
        td, th { word-wrap: break-word; }
    </style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', [
        'title' => $title,
        'meta' => Ar::glyphs($meta),
        'centered' => true,
        'useGlyphs' => false,
    ])

    <table class="lines">
        <thead>
        <tr>
            <th>Client</th>
            <th>Code</th>
            <th>Salesman</th>
            <th>Suspended by</th>
            <th>When</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->client_code ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->salesman_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->suspended_by ?? '')) }}</td>
                <td>{{ $row->suspended_at ?? '' }}</td>
            </tr>
        @empty
            <tr><td colspan="5">No suspended clients.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
