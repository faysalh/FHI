@php
    use App\Support\ArabicPdfText as Ar;
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Deliveries report</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; margin: 12px; direction: ltr; unicode-bidi: normal; }
        h1 { font-size: 14px; margin: 0 0 8px 0; }
        .meta { font-size: 9px; color: #444; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; direction: ltr; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 3px 4px; text-align: left; word-wrap: break-word; }
        th { background: #f0f0f0; font-size: 9px; }
        td.num { text-align: right; }
        td.ok { background: #dcfce7; color: #166534; font-weight: 600; }
        td.no { background: #fee2e2; color: #991b1b; font-weight: 600; }
    </style>
</head>
<body>
    @php
        $meta = 'Period: '.$dateFrom.' — '.$dateTo;
        $meta .= ($cities ?? []) !== [] ? ' | Cities: '.implode('; ', $cities) : ' | Cities: all';
        $meta .= trim((string) ($storage ?? '')) !== '' ? ' | Storage: '.$storage : ' | Storage: all';
        $meta .= ! empty($teamId ?? null) ? ' | Team filter: #'.$teamId : ' | Team filter: all';
    @endphp
    <h1>{{ Ar::glyphsKeepLatinDigits('Deliveries report') }}</h1>
    <div class="meta">{{ Ar::glyphsKeepLatinDigits($meta) }}</div>
    <table>
        <thead>
        <tr>
            <th>{{ Ar::glyphsKeepLatinDigits('Invoice number') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Date') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Client code') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Client name') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('City') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Storage') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Quantity (pcs)') }}</th>
            @if (($includeAmount ?? false) === true)
                <th>{{ Ar::glyphsKeepLatinDigits('Amount') }}</th>
            @endif
            @if (($includeWeight ?? false) === true)
                <th>{{ Ar::glyphsKeepLatinDigits('Weight') }}</th>
            @endif
            <th>{{ Ar::glyphsKeepLatinDigits('Status') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Team') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            @php $delivered = strtolower((string) ($row->delivery_status ?? '')) === 'delivered'; @endphp
            <tr>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->invoice_no ?? $row->invoice_id ?? '')) }}</td>
                <td>{{ (string) ($row->document_date ?? '') }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->client_code ?? '')) }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->client_name ?? '')) }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->city_name ?? '')) }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->storage_name ?? '')) }}</td>
                <td class="num">{{ display_number((float) ($row->quantity ?? 0)) }}</td>
                @if (($includeAmount ?? false) === true)
                    <td class="num">{{ display_number((float) ($row->amount ?? 0)) }}</td>
                @endif
                @if (($includeWeight ?? false) === true)
                    <td class="num">{{ display_number((float) ($row->weight_total ?? 0)) }}</td>
                @endif
                <td class="{{ $delivered ? 'ok' : 'no' }}">{{ $delivered ? 'Delivered' : 'Not delivered' }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->team_name ?? '')) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>

