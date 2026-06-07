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
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', [
        'title' => 'Deliveries report',
        'meta' => $meta,
        'glyphsKeepLatinDigits' => true,
    ])
    <table>
        <thead>
        <tr>
            <th>{{ Ar::glyphsKeepLatinDigits('#') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Invoice number') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Date') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Client name') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Quantity (pcs)') }}</th>
            <th>{{ Ar::glyphsKeepLatinDigits('Team') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->invoice_no ?? $row->invoice_id ?? '')) }}</td>
                <td>{{ (string) ($row->document_date ?? '') }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->client_name ?? '')) }}</td>
                <td class="num">{{ display_number((float) ($row->quantity ?? 0)) }}</td>
                <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->team_name ?? '')) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
