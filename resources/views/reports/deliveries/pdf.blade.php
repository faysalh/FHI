@php
    use App\Support\ArabicPdfText as Ar;

    $meta = 'Period: '.$dateFrom.' — '.$dateTo;
    $meta .= ($cities ?? []) !== [] ? ' | Cities: '.implode('; ', $cities) : ' | Cities: all';
    $meta .= trim((string) ($storage ?? '')) !== '' ? ' | Storage: '.$storage : ' | Storage: all';
    $meta .= ! empty($teamId ?? null) ? ' | Team filter: #'.$teamId : ' | Team filter: all';
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Deliveries report</title>
    <style>
        @include('reports.partials.pdf-styles')
        table { table-layout: auto; font-size: 8px; }
        th, td { padding: 2px 3px; line-height: 1.2; vertical-align: middle; }
        th { font-size: 8px; }
        th.col-num, td.col-num { width: 1%; white-space: nowrap; text-align: right; }
        th.col-invoice, td.col-invoice { width: 1%; white-space: nowrap; }
        th.col-qty, td.col-qty { width: 1%; white-space: nowrap; }
        th.col-client, td.col-client { word-wrap: break-word; }
    </style>
</head>
<body>
    @include('reports.partials.pdf-branding-header', [
        'pdfHeaderTitle' => 'Deliveries report',
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
            <th class="col-invoice">{{ Ar::glyphsKeepLatinDigits('Invoice number') }}</th>
            <th class="col-client">{{ Ar::glyphsKeepLatinDigits('Client name') }}</th>
            <th class="col-qty">{{ Ar::glyphsKeepLatinDigits('Quantity (pcs)') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td class="col-num">{{ $loop->iteration }}</td>
                <td class="col-invoice">{{ Ar::glyphsKeepLatinDigits((string) ($row->invoice_no ?? $row->invoice_id ?? '')) }}</td>
                <td class="col-client">{{ Ar::glyphsKeepLatinDigits((string) ($row->client_name ?? '')) }}</td>
                <td class="col-qty num">{{ display_number((float) ($row->quantity ?? 0)) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
