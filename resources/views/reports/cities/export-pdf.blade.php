@php
    use App\Support\ArabicPdfText as Ar;

    $pdfMeta = 'Period: '.$dateFrom.' — '.$dateTo;
    $pdfMeta .= ' | Mode: '.$modeLabel;
    if (! empty($q)) {
        $pdfMeta .= ' | Category filter: '.$q;
    }
    if (! empty($citiesLabel)) {
        $pdfMeta .= ' | Cities: '.$citiesLabel;
    } else {
        $pdfMeta .= ' | Cities: all';
    }
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Cities sales report</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', ['title' => 'Cities sales report', 'meta' => $pdfMeta])

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

    <p class="pdf-note">{{ Ar::glyphs('Exported rows are capped at '.$exportCap.' for PDF/CSV.') }}</p>
    @include('reports.partials.pdf-footer')
</body>
</html>
