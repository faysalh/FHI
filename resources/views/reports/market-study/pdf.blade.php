@php
    use App\Support\ArabicPdfText as Ar;

    $title = 'Market Study';
    $metaParts = [];
    if (($filters['market'] ?? '') !== '') {
        $metaParts[] = 'Market: '.$filters['market'];
    }
    if (($filters['item'] ?? '') !== '') {
        $metaParts[] = 'Item: '.$filters['item'];
    }
    if (($filters['brand'] ?? '') !== '') {
        $metaParts[] = 'Brand: '.$filters['brand'];
    }
    if (($filters['date_from'] ?? '') !== '' || ($filters['date_to'] ?? '') !== '') {
        $metaParts[] = 'Logged: '.(($filters['date_from'] ?? '') !== '' ? $filters['date_from'] : '…')
            .' — '.(($filters['date_to'] ?? '') !== '' ? $filters['date_to'] : '…');
    }
    if (($filters['q'] ?? '') !== '') {
        $metaParts[] = 'Search: '.$filters['q'];
    }
    $rangeAvg = $insights['range_average'] ?? null;
    if ($rangeAvg !== null) {
        $metaParts[] = 'Range avg: '.display_number((float) $rangeAvg, 4).' / KG';
    }
    $todayPrice = $insights['today_price'] ?? null;
    if ($todayPrice !== null) {
        $metaParts[] = 'Today: '.display_number((float) $todayPrice, 4).' / KG';
    }
    $meta = implode(' | ', $metaParts);
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
            <th class="center idx-col">#</th>
            <th class="center">Logged on</th>
            <th>Market</th>
            <th>Item</th>
            <th>Brand</th>
            <th class="center">Price / KG</th>
            <th>Notes</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $index => $row)
            <tr>
                <td class="center idx-col">{{ $index + 1 }}</td>
                <td class="center">{{ $row->logged_on ?? '' }}</td>
                <td>{{ Ar::glyphs((string) ($row->market_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->item_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->brand ?? '')) }}</td>
                <td class="center">{{ display_number((float) ($row->price_per_kg ?? 0), 4) }}</td>
                <td>{{ Ar::glyphs((string) ($row->notes ?? '')) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="center">No entries.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
