@php
    use App\Support\ArabicPdfText as Ar;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ Ar::glyphs('General ledger') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; direction: ltr; unicode-bidi: normal; }
        h1 { font-size: 14px; margin: 0 0 6px; }
        .meta { margin: 0 0 10px; color: #444; }
        table { width: 100%; border-collapse: collapse; direction: ltr; }
        th, td { border: 1px solid #ccc; padding: 3px 4px; vertical-align: top; word-wrap: break-word; }
        th { background: #f3f4f6; text-align: left; }
        .num { text-align: right; white-space: nowrap; }
        tfoot th { background: #eef2ff; }
        .opening { background: #f8fafc; font-weight: bold; }
    </style>
</head>
<body>
@include('reports.partials.pdf-branding-header')
<h1>{{ Ar::glyphs('General ledger') }}</h1>
<p class="meta">
    {{ Ar::glyphs(($filters['date_from'] ?? '').' → '.($filters['date_to'] ?? '')) }}
    · {{ Ar::glyphs('Account: '.(($accountLabel ?? '') !== '' ? $accountLabel : 'All (scoped)')) }}
    · {{ Ar::glyphs('Salesman: '.(($salesmanName ?? '') !== '' ? $salesmanName : 'All')) }}
    · {{ Ar::glyphs('Year: '.($yearName ?? '—')) }}
    · {{ Ar::glyphs('Currency: '.($currencyLabel ?? '—')) }}
</p>
<table>
    <thead>
    <tr>
        <th>{{ Ar::glyphs('Account') }}</th>
        <th>{{ Ar::glyphs('Type') }}</th>
        <th>{{ Ar::glyphs('Invoice #') }}</th>
        <th>{{ Ar::glyphs('Date') }}</th>
        <th class="num">{{ Ar::glyphs('Debit') }}</th>
        <th class="num">{{ Ar::glyphs('Credit') }}</th>
        <th class="num">{{ Ar::glyphs('Balance') }}</th>
        <th>{{ Ar::glyphs('Cross account') }}</th>
        <th>{{ Ar::glyphs('Description') }}</th>
        <th>{{ Ar::glyphs('Notes') }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse (($rows ?? []) as $row)
        @php
            $isOpening = (string) ($row->flag ?? '') === '0';
            $dateVal = $row->register_date ?? null;
            $dateStr = '';
            if ($dateVal !== null && $dateVal !== '') {
                try { $dateStr = \Illuminate\Support\Carbon::parse((string) $dateVal)->format('Y-m-d'); } catch (\Throwable) { $dateStr = (string) $dateVal; }
            }
            $accountText = trim((string) ($row->account_code ?? '').' '.(string) ($row->account_name ?? ''));
        @endphp
        <tr class="{{ $isOpening ? 'opening' : '' }}">
            <td>{{ Ar::glyphs($accountText) }}</td>
            <td>{{ Ar::glyphs((string) ($row->document_type ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->document_no ?? '')) }}</td>
            <td>{{ $dateStr }}</td>
            <td class="num">{{ display_number((float) ($row->debit ?? 0)) }}</td>
            <td class="num">{{ display_number((float) ($row->credit ?? 0)) }}</td>
            <td class="num">{{ display_number((float) ($row->balance ?? 0)) }}</td>
            <td>{{ Ar::glyphs((string) ($row->cross_account ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->description ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->notes ?? '')) }}</td>
        </tr>
    @empty
        <tr><td colspan="10">{{ Ar::glyphs('No ledger lines for these filters.') }}</td></tr>
    @endforelse
    </tbody>
    <tfoot>
    <tr>
        <th colspan="4">{{ Ar::glyphs('Period totals (excludes opening)') }}</th>
        <th class="num">{{ display_number($periodDebit ?? 0) }}</th>
        <th class="num">{{ display_number($periodCredit ?? 0) }}</th>
        <th class="num">{{ display_number(($periodDebit ?? 0) - ($periodCredit ?? 0)) }}</th>
        <th colspan="3"></th>
    </tr>
    </tfoot>
</table>
</body>
</html>
