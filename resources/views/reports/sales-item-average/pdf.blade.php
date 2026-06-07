@php
    use App\Support\ArabicPdfText as Ar;

    $meta = 'Period: '.$dateFrom.' — '.$dateTo;
    if ($q !== '') {
        $meta .= ' | Item filter: '.$q;
    }
    if (! empty($category ?? '')) {
        $meta .= ' | Category drilldown: '.$category;
    }
    if (($includeItemBreakdown ?? false) === true) {
        $meta .= ' | Layout: categorized item breakdown';
    }
    if (! empty($cities ?? [])) {
        $meta .= ' | Cities: '.implode('; ', $cities);
    } else {
        $meta .= ' | Cities: all';
    }
    if ($workingDays > 0) {
        $meta .= ' | Business days (Fri + holidays excluded): '.$workingDays;
    }
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Sales by item average</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', [
        'title' => 'Sales by item average',
        'meta' => $meta,
        'glyphsKeepLatinDigits' => true,
    ])
    <table>
        <thead>
        <tr>
            <th>{{ Ar::glyphsKeepLatinDigits('Category') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Item name') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Quantity (pcs)') }}</th>
            @if ($workingDays > 0)
                <th>{{ Ar::glyphsKeepLatinDigits('Avg quantity / day (pcs)') }}</th>
                <th>{{ Ar::glyphsKeepLatinDigits('Balance coverage (days)') }}</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            @php
                $units = (float) ($row->units_sold ?? 0);
                $storage = (float) ($row->storage_balance ?? 0);
                $avgUnits = $workingDays > 0 ? ($units / $workingDays) : null;
            @endphp
            <tr>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->category_name ?? '')) }}</td>
                <td>{{ (string) ($row->item_name ?? '') }}</td>
                <td class="num">{{ display_number($units) }}</td>
                @if ($workingDays > 0)
                    <td class="num">{{ display_number($avgUnits) }}</td>
                    <td class="num">
                        @if (($avgUnits ?? 0.0) > 0)
                            {{ display_number($storage / $avgUnits) }}
                        @else
                            —
                        @endif
                    </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
