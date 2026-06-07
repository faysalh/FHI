@php
    use App\Support\ArabicPdfText as Ar;

    $filtersData = is_array($filters ?? null) ? $filters : [];
    $asOfDate = (string) ($filtersData['as_of_date'] ?? '');
    $storages = array_values(array_filter((array) ($filtersData['storages'] ?? [])));
    $categories = array_values(array_filter((array) ($filtersData['categories'] ?? [])));
    $excludeCategories = array_values(array_filter((array) ($filtersData['exclude_categories'] ?? [])));
    $cities = array_values(array_filter((array) ($filtersData['cities'] ?? [])));
    $rowList = is_array($rows ?? null) ? $rows : [];
    $categoryTotalsMap = is_array($categoryTotals ?? null) ? $categoryTotals : [];
    $showCategory = (bool) ($filtersData['show_category'] ?? false);
    $showItemCode = (bool) ($filtersData['show_item_code'] ?? false);
    $labelColspan = 1 + ($showCategory ? 1 : 0) + ($showItemCode ? 1 : 0);

    $metaLines = ['As of: '.$asOfDate];
    if ($storages !== []) {
        $metaLines[] = 'Storages: '.implode(', ', $storages);
    }
    if ($categories !== []) {
        $metaLines[] = 'Categories: '.implode(', ', $categories);
    }
    if ($excludeCategories !== []) {
        $metaLines[] = 'Excluded categories: '.implode(', ', $excludeCategories);
    }
    if (($governorateLabel ?? 'None') !== 'None') {
        $metaLines[] = 'Governorate: '.(string) $governorateLabel;
    }
    if ($cities !== []) {
        $metaLines[] = 'Store cities: '.implode(', ', $cities);
    }
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Storage report</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', ['title' => 'Storage report', 'meta' => $metaLines])

    <div class="pdf-summary-bar">
        <strong>{{ Ar::glyphs('Grand total') }}</strong> —
        {{ Ar::glyphs('Quantity') }}: {{ display_number((float) ($totals['quantity_total'] ?? 0)) }} |
        {{ Ar::glyphs('Weight (kg)') }}: {{ display_number((float) ($totals['weight_total'] ?? 0)) }}
    </div>

    <table>
        <thead>
        <tr>
            <th>#</th>
            @if ($showCategory)<th>{{ Ar::glyphs('Category') }}</th>@endif
            @if ($showItemCode)<th>{{ Ar::glyphs('Item code') }}</th>@endif
            <th>{{ Ar::glyphs('Item name') }}</th>
            <th class="num">{{ Ar::glyphs('Quantity') }}</th>
            <th class="num">{{ Ar::glyphs('Weight (kg)') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rowList as $i => $row)
            @php
                $cat = trim((string) ($row->category_name ?? ''));
                if ($cat === '') {
                    $cat = '(uncategorized)';
                }
                $next = $rowList[$i + 1] ?? null;
                $nextCat = $next ? trim((string) ($next->category_name ?? '')) : '';
                if ($nextCat === '') {
                    $nextCat = $next ? '(uncategorized)' : '';
                }
                $showCategorySubtotal = $next === null || $nextCat !== $cat;
            @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                @if ($showCategory)<td>{{ Ar::glyphs((string) ($row->category_name ?? '')) }}</td>@endif
                @if ($showItemCode)<td>{{ Ar::glyphs((string) ($row->item_code ?? '')) }}</td>@endif
                <td>{{ Ar::glyphs((string) ($row->item_name ?? '')) }}</td>
                <td class="num">{{ display_number((float) ($row->quantity_total ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->weight_total ?? 0)) }}</td>
            </tr>
            @if ($showCategorySubtotal)
                @php $catSum = $categoryTotalsMap[$cat] ?? ['quantity_total' => 0, 'weight_total' => 0]; @endphp
                <tr class="category-subtotal">
                    <td></td>
                    <td colspan="{{ $labelColspan }}">{{ Ar::glyphs($cat.' — subtotal') }}</td>
                    <td class="num">{{ display_number($catSum['quantity_total'] ?? 0) }}</td>
                    <td class="num">{{ display_number($catSum['weight_total'] ?? 0) }}</td>
                </tr>
            @endif
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            <td colspan="{{ 1 + $labelColspan }}">{{ Ar::glyphs('Grand total') }}</td>
            <td class="num">{{ display_number((float) ($totals['quantity_total'] ?? 0)) }}</td>
            <td class="num">{{ display_number((float) ($totals['weight_total'] ?? 0)) }}</td>
        </tr>
        </tfoot>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
