@php
    use App\Support\ArabicPdfText as Ar;
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Sales by item average</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; margin: 12px; direction: ltr; unicode-bidi: normal; }
        h1 { font-size: 14px; margin: 0 0 8px 0; }
        .meta { font-size: 9px; color: #444; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; direction: ltr; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 3px 4px; text-align: left; word-wrap: break-word; }
        th { background: #f0f0f0; font-size: 9px; }
        td.num { text-align: right; }
    </style>
</head>
<body>
    @php
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
            $meta .= ' | Working days: '.$workingDays;
        } else {
            $meta .= ' | Working days: not set';
        }
    @endphp
    <h1>{{ Ar::glyphsKeepLatinDigits('Sales by item average') }}</h1>
    <div class="meta">{{ Ar::glyphsKeepLatinDigits($meta) }}</div>
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
</body>
</html>
