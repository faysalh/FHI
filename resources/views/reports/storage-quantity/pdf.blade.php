@php
    use App\Support\ArabicPdfText as Ar;

    $filtersData = is_array($filters ?? null) ? $filters : [];
    $isAdv = ($filtersData['balance_mode'] ?? 'normal') === 'adv';
    $rowList = is_array($rows ?? null) ? $rows : [];

    $metaLines = [
        'Mode: '.($isAdv ? 'Adv (SP_Get_Item_Balance_Adv)' : 'Normal (SP_Get_Item_Balance)'),
        'Year ID: '.(string) ($filtersData['year_id'] ?? ''),
    ];
    if (($filtersData['expiration_date'] ?? '') !== '') {
        $metaLines[] = 'Expiration: '.(string) $filtersData['expiration_date'];
    }
    if ($isAdv && ($filtersData['as_of_datetime'] ?? '') !== '') {
        $metaLines[] = 'As of: '.(string) $filtersData['as_of_datetime'];
    }
    $storages = array_values(array_filter((array) ($filtersData['storages'] ?? [])));
    if ($storages !== []) {
        $metaLines[] = 'Storages: '.implode(', ', $storages);
    }
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Storage quantity</title>
    <style>
        @include('reports.partials.pdf-styles')
        body { direction: ltr; unicode-bidi: normal; font-size: 11px; }
        table { direction: ltr; width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; word-wrap: break-word; }
        th { background: #f1f5f9; text-align: left; }
        .num { text-align: right; }
    </style>
</head>
<body>
@include('reports.partials.pdf-branding-header', ['branding' => $branding ?? null])
@include('reports.partials.pdf-title-block', ['title' => 'Storage quantity', 'meta' => $metaLines])

<div class="pdf-summary-bar">
    <strong>{{ Ar::glyphs('Totals') }}</strong> —
    {{ Ar::glyphs('Balance') }}: {{ display_number((float) ($totals['balance_total'] ?? 0)) }}
    @if (!$isAdv)
        | {{ Ar::glyphs('In store') }}: {{ display_number((float) ($totals['in_store_total'] ?? 0)) }}
    @endif
</div>

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>{{ Ar::glyphs('Category') }}</th>
        <th>{{ Ar::glyphs('Item code') }}</th>
        <th>{{ Ar::glyphs('Item name') }}</th>
        <th>{{ Ar::glyphs('Storage') }}</th>
        <th class="num">{{ Ar::glyphs('Balance') }}</th>
        @if (!$isAdv)<th class="num">{{ Ar::glyphs('In store') }}</th>@endif
    </tr>
    </thead>
    <tbody>
    @foreach ($rowList as $index => $row)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ Ar::glyphs((string) ($row->category_name ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->item_code ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->item_name ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->storage_name ?? '')) }}</td>
            <td class="num">{{ display_number((float) ($row->balance ?? 0)) }}</td>
            @if (!$isAdv)<td class="num">{{ display_number((float) ($row->in_store ?? 0)) }}</td>@endif
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
