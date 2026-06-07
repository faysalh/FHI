@php
    use App\Support\ArabicPdfText as Ar;

    $stor = trim((string) ($storage ?? ''));
    $cat = trim((string) ($category ?? ''));
    $exCats = array_values(array_filter(
        array_map(static fn ($v): string => trim((string) $v), (array) ($excludeCategories ?? [])),
        static fn (string $s): bool => $s !== ''
    ));
    $it = trim((string) ($item ?? ''));
    $wd = max(1, (int) ($workingDays ?? 1));
    $meta = 'Inventory as of: '.$asOfDate.' | Sales: '.$salesDateFrom.' – '.$salesDateTo.' | Working days (Fri excluded): '.$wd;
    $meta .= ' | Storage: '.($stor !== '' ? $stor : 'all');
    $meta .= ' | Category: '.($cat !== '' ? $cat : 'all');
    $meta .= ' | Exclude categories: '.($exCats !== [] ? implode(', ', $exCats) : 'none');
    $meta .= ' | Item filter: '.($it !== '' ? $it : 'none');
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Storage items report</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', [
        'title' => 'Storage items — inventory & sales',
        'meta' => $meta,
        'glyphsKeepLatinDigits' => true,
    ])

    <table>
        <thead>
        <tr>
            <th>{{ Ar::glyphsKeepLatinDigits('#') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Item code') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Category') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Item name') }}</th>
            <th class="num">{{ Ar::glyphsKeepLatinDigits('Carton') }}</th>
            <th class="num">{{ Ar::glyphsKeepLatinDigits('Sales') }}</th>
            <th class="num">{{ Ar::glyphsKeepLatinDigits('Sales average') }}</th>
            <th class="num">{{ Ar::glyphsKeepLatinDigits('Forecast') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            @php
                $sold = (float) ($row->sold_quantity_period ?? 0);
                $qty = (float) ($row->quantity_total ?? 0);
                $avg = ($wd > 0) ? ($sold / $wd) : 0.0;
                $cover = ($sold > 0 && $avg > 0) ? ($qty / $avg) : null;
                $forecastTrClass = ($cover !== null && $cover < 5) ? 'forecast-below-5' : (($cover !== null && $cover < 10) ? 'forecast-below-10' : '');
            @endphp
            <tr class="{{ $forecastTrClass }}">
                <td>{{ $loop->iteration }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->item_code ?? '')) }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->category_name ?? '')) }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->item_name ?? '')) }}</td>
                <td class="num">{{ display_number($qty) }}</td>
                <td class="num">{{ display_number($sold) }}</td>
                <td class="num">{{ display_number($avg) }}</td>
                <td class="num">@if ($cover !== null){{ display_number($cover) }}@else{{ Ar::glyphsKeepLatinDigits('—') }}@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
