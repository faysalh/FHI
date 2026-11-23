@php
    use App\Support\ArabicPdfText as Ar;

    $meta = 'Period: '.$dateFrom.' — '.$dateTo;
    $meta .= ($cities ?? []) !== [] ? ' | Cities: '.implode('; ', $cities) : ' | Cities: all';
    $meta .= trim((string) ($storage ?? '')) !== '' ? ' | Storage: '.$storage : ' | Storage: all';
    $meta .= ! empty($teamId ?? null) ? ' | Team filter: #'.$teamId : ' | Team filter: all';
    $meta .= trim((string) ($deliveryStatus ?? '')) !== '' ? ' | Status: '.$deliveryStatus : '';
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Deliveries item export</title>
    <style>
        @include('reports.partials.pdf-styles')
        table { table-layout: auto; font-size: 8px; }
        th, td { padding: 2px 3px; line-height: 1.2; vertical-align: middle; }
        th { font-size: 8px; }
        th.col-num, td.col-num { width: 1%; white-space: nowrap; text-align: right; }
        th.col-qty, td.col-qty,
        th.col-weight, td.col-weight { width: 1%; white-space: nowrap; }
        th.col-item, td.col-item { word-wrap: break-word; }
        tr.totals td { background: #f1f5f9; font-weight: 700; }
    </style>
</head>
<body>
    @include('reports.partials.pdf-branding-header', [
        'pdfHeaderTitle' => 'Deliveries — item export',
    ])
    @include('reports.partials.pdf-title-block', [
        'title' => '',
        'meta' => $meta,
        'glyphsKeepLatinDigits' => true,
    ])
    <table>
        <thead>
        <tr>
            <th class="col-num">{{ Ar::glyphsKeepLatinDigits('#') }}</th>
            <th class="col-item">{{ Ar::glyphsKeepLatinDigits('Item') }}</th>
            <th class="col-qty">{{ Ar::glyphsKeepLatinDigits('Quantity (pcs)') }}</th>
            <th class="col-weight">{{ Ar::glyphsKeepLatinDigits('Weight') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td class="col-num">{{ $loop->iteration }}</td>
                <td class="col-item">{{ Ar::glyphsKeepLatinDigits((string) ($row->item_name ?? '')) }}</td>
                <td class="col-qty num">{{ display_number((float) ($row->quantity ?? 0)) }}</td>
                <td class="col-weight num">{{ display_number((float) ($row->weight_total ?? 0)) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4">{{ Ar::glyphsKeepLatinDigits('No items match the filters.') }}</td>
            </tr>
        @endforelse
        @if (count($rows) > 0)
            <tr class="totals">
                <td class="col-num"></td>
                <td class="col-item">{{ Ar::glyphsKeepLatinDigits('Total') }}</td>
                <td class="col-qty num">{{ display_number((float) ($totalQty ?? 0)) }}</td>
                <td class="col-weight num">{{ display_number((float) ($totalWeight ?? 0)) }}</td>
            </tr>
        @endif
        </tbody>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
