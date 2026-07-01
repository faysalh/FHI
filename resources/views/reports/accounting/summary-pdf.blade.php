<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Accounting summary</title>
    <style>
        @include('reports.partials.pdf-styles')
        body { direction: ltr; unicode-bidi: normal; }
        table { direction: ltr; table-layout: auto; width: 100%; margin-bottom: 16px; }
        th, td { word-wrap: break-word; padding: 4px 6px; }
        .num { text-align: right; }
        h2 { font-size: 14px; margin: 16px 0 8px; }
    </style>
</head>
<body>
@include('reports.partials.pdf-branding-header')
@include('reports.partials.pdf-title-block', [
    'title' => 'Accounting summary',
    'meta' => 'Period: '.$dateFrom.' — '.$dateTo,
])

<h2>Cash</h2>
<table>
    <thead>
    <tr>
        <th>Date</th>
        <th class="num">Opening IQD</th>
        <th class="num">Spent IQD</th>
        <th class="num">Remaining IQD</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($cashSummary as $row)
        <tr>
            <td>{{ $row->sheet_date ?? '' }}</td>
            <td class="num">{{ display_number((float) ($row->opening_amount ?? 0)) }}</td>
            <td class="num">{{ display_number((float) ($row->spent_total ?? 0)) }}</td>
            <td class="num">{{ display_number((float) ($row->remaining_total ?? 0)) }}</td>
        </tr>
    @empty
        <tr><td colspan="4">No cash sheets in range.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Transfers</h2>
<table>
    <thead>
    <tr>
        <th>Date</th>
        <th class="num">Rows</th>
        <th class="num">IQD equivalent</th>
        <th class="num">USD rows</th>
        <th class="num">USD amount</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($transferSummary as $row)
        <tr>
            <td>{{ $row->transfer_date ?? '' }}</td>
            <td class="num">{{ display_number((int) ($row->row_count ?? 0)) }}</td>
            <td class="num">{{ display_number((float) ($row->iqd_total ?? 0)) }}</td>
            <td class="num">{{ display_number((int) ($row->usd_row_count ?? 0)) }}</td>
            <td class="num">{{ display_number((float) ($row->usd_amount_total ?? 0)) }}</td>
        </tr>
    @empty
        <tr><td colspan="5">No transfers in range.</td></tr>
    @endforelse
    </tbody>
</table>
@include('reports.partials.pdf-footer')
</body>
</html>
