@php
    use App\Support\ArabicPdfText as Ar;

    $invoiceDirection = (($branding['invoice_direction'] ?? 'ltr') === 'rtl') ? 'rtl' : 'ltr';
    $isRtlInvoice = $invoiceDirection === 'rtl';
    $currencyLabel = $isRtlInvoice ? 'د.ع' : 'IQD';
    $title = $isRtlInvoice ? 'تقرير التلفيات' : 'Damaged goods report';
    $periodLabel = $isRtlInvoice ? 'الفترة' : 'Period';
    $filtersLabel = $isRtlInvoice ? 'عوامل التصفية' : 'Filters';
    $colIdx = $isRtlInvoice ? '#' : '#';
    $colDate = $isRtlInvoice ? 'التاريخ' : 'Date';
    $colClient = $isRtlInvoice ? 'اسم الزبون' : 'Client name';
    $colItem = $isRtlInvoice ? 'المادة المرجعة' : 'Returned item';
    $colQty = $isRtlInvoice ? 'كمية التلف (قطعة)' : 'Damaged qty (pieces)';
    $colAmt = $isRtlInvoice ? 'المبلغ' : 'Amount';
    $totalLabel = $isRtlInvoice ? 'الإجمالي' : 'Totals';
    if ($isRtlInvoice) {
        $title = Ar::glyphsKeepLatinDigits($title);
        $periodLabel = Ar::glyphsKeepLatinDigits($periodLabel);
        $filtersLabel = Ar::glyphsKeepLatinDigits($filtersLabel);
        $colDate = Ar::glyphsKeepLatinDigits($colDate);
        $colClient = Ar::glyphsKeepLatinDigits($colClient);
        $colItem = Ar::glyphsKeepLatinDigits($colItem);
        $colQty = Ar::glyphsKeepLatinDigits($colQty);
        $colAmt = Ar::glyphsKeepLatinDigits($colAmt);
        $totalLabel = Ar::glyphsKeepLatinDigits($totalLabel);
    }

    $meta = $periodLabel.': '.$dateFrom.' — '.$dateTo;
    if ($clientFilter !== '' || $itemFilter !== '' || ($salesmanFilter ?? '') !== '') {
        $meta .= ' | '.$filtersLabel.':';
        if ($clientFilter !== '') {
            $meta .= ' '.($isRtlInvoice ? Ar::glyphs('زبون: ') : 'Client: ').Ar::glyphs((string) $clientFilter);
        }
        if ($itemFilter !== '') {
            $meta .= ($clientFilter !== '' ? ' | ' : ' ').($isRtlInvoice ? Ar::glyphs('مادة: ') : 'Item: ').Ar::glyphs((string) $itemFilter);
        }
        if (($salesmanFilter ?? '') !== '') {
            $meta .= (($clientFilter !== '' || $itemFilter !== '') ? ' | ' : ' ').($isRtlInvoice ? Ar::glyphs('مندوب: ') : 'Salesman: ').Ar::glyphs(trim((string) ($salesmanFilterName ?? '')) !== '' ? (string) $salesmanFilterName : (string) $salesmanFilter);
        }
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $isRtlInvoice ? 'ar' : 'en' }}" dir="{{ $invoiceDirection }}">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', [
        'title' => $title,
        'meta' => $meta,
        'centered' => true,
        'useGlyphs' => false,
    ])

    <table class="lines">
        <thead>
        <tr>
            <th class="center idx-col">{{ $colIdx }}</th>
            <th class="center date-col">{{ $colDate }}</th>
            <th>{{ $colClient }}</th>
            <th>{{ $colItem }}</th>
            <th class="num">{{ $colQty }}</th>
            <th class="num">{{ $colAmt }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $index => $row)
            <tr>
                <td class="center idx-col">{{ $index + 1 }}</td>
                <td class="center date-col">{{ Ar::glyphsKeepLatinDigits((string) ($row->occurred_date ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->client_name_snapshot ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->item_name_snapshot ?? '')) }}</td>
                <td class="num">{{ display_number((int) ($row->damaged_pieces ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->amount_total ?? 0)) }} {{ $currencyLabel }}</td>
            </tr>
        @endforeach
        </tbody>
        @if (count($rows) > 0)
        <tfoot>
        <tr>
            <td class="idx-col"></td>
            <td class="date-col"></td>
            <td colspan="2">{{ $totalLabel }}</td>
            <td class="num">{{ display_number($sumQty) }}</td>
            <td class="num">{{ display_number($sumAmt) }} {{ $currencyLabel }}</td>
        </tr>
        </tfoot>
        @endif
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
