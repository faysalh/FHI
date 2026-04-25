@php
    use App\Support\ArabicPdfText as Ar;

    $pdfMeta = 'Period: '.$dateFrom.' — '.$dateTo;
    $pdfMeta .= ' | Mode: '.$modeLabel;
    if (! empty($q)) {
        $pdfMeta .= ' | Category filter: '.$q;
    }
    if (! empty($customerLabel)) {
        $pdfMeta .= ' | Customers: '.$customerLabel;
    } else {
        $pdfMeta .= ' | Customers: all';
    }
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Sales report</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; margin: 12px; direction: ltr; unicode-bidi: normal; }
        h1 { font-size: 14px; margin: 0 0 8px 0; }
        .meta { font-size: 9px; color: #444; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; direction: ltr; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; word-wrap: break-word; }
        th { background: #f0f0f0; font-size: 9px; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
    <h1>{{ Ar::glyphs('Sales report') }}</h1>
    <div class="meta">{{ Ar::glyphs($pdfMeta) }}</div>

    @if ($mode === 'totals' && isset($rows[0]))
        @php $t = $rows[0]; @endphp
        <table>
            <thead>
            <tr>
                <th>{{ Ar::glyphs('Quantity (pcs)') }}</th>
                <th>{{ Ar::glyphs('Amount (IQD)') }}</th>
                <th>{{ Ar::glyphs('Weight (kg)') }}</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="num">{{ display_number($t->units_sold ?? 0) }}</td>
                <td class="num">{{ display_number($t->amount ?? 0) }}</td>
                <td class="num">{{ display_number($t->weight_total ?? 0) }}</td>
            </tr>
            </tbody>
        </table>
    @endif

    @if ($mode === 'by_client')
        <table>
            <thead>
            <tr>
                <th>{{ Ar::glyphs('Client code') }}</th>
                <th>{{ Ar::glyphs('Client name') }}</th>
                <th class="num">{{ Ar::glyphs('Quantity (pcs)') }}</th>
                <th class="num">{{ Ar::glyphs('Amount (IQD)') }}</th>
                <th class="num">{{ Ar::glyphs('Weight (kg)') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ Ar::glyphs((string) ($row->client_code ?? '')) }}</td>
                    <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
                    <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    <td class="num">{{ display_number($row->weight_total ?? 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($mode === 'by_category')
        <table>
            <thead>
            <tr>
                <th>{{ Ar::glyphs('Category') }}</th>
                <th class="num">{{ Ar::glyphs('Quantity (pcs)') }}</th>
                <th class="num">{{ Ar::glyphs('Amount (IQD)') }}</th>
                <th class="num">{{ Ar::glyphs('Weight (kg)') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ Ar::glyphs((string) ($row->chicken_category ?? '')) }}</td>
                    <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    <td class="num">{{ display_number($row->weight_total ?? 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($mode === 'by_category_by_client')
        <table>
            <thead>
            <tr>
                <th>{{ Ar::glyphs('Client code') }}</th>
                <th>{{ Ar::glyphs('Client name') }}</th>
                <th>{{ Ar::glyphs('Category') }}</th>
                <th class="num">{{ Ar::glyphs('Quantity (pcs)') }}</th>
                <th class="num">{{ Ar::glyphs('Amount (IQD)') }}</th>
                <th class="num">{{ Ar::glyphs('Weight (kg)') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ Ar::glyphs((string) ($row->client_code ?? '')) }}</td>
                    <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
                    <td>{{ Ar::glyphs((string) ($row->chicken_category ?? '')) }}</td>
                    <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    <td class="num">{{ display_number($row->weight_total ?? 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <p style="font-size: 8px; color: #666; margin-top: 12px;">{{ Ar::glyphs('Exported rows are capped at '.$exportCap.' for PDF/CSV.') }}</p>
</body>
</html>
