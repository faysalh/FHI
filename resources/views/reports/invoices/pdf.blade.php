<!DOCTYPE html>
@php
    use App\Support\ArabicPdfText as Ar;

    $invoiceDirection = (($branding['invoice_direction'] ?? 'ltr') === 'rtl') ? 'rtl' : 'ltr';
    $isRtlInvoice = $invoiceDirection === 'rtl';
    $currencyLabel = $isRtlInvoice ? 'د.ع' : 'IQD';
    $labels = $isRtlInvoice
        ? [
            'title' => 'فاتورة بيع',
            'invoice_no' => 'رقم الفاتورة:',
            'printed_at' => 'تاريخ الطباعة:',
            'created_at' => 'وقت الإنشاء:',
            'salesman_phone' => 'رقم المندوب:',
            'client_phone' => 'الهاتف:',
            'store' => 'المستودع:',
            'date' => 'التاريخ:',
            'salesman' => 'المندوب:',
            'account' => 'اسم الزبون:',
            'address' => 'العنوان:',
            'city' => 'المدينة:',
            'weight' => 'الوزن:',
            'item_code' => 'كود المادة',
            'item_name' => 'اسم المادة',
            'quantity' => 'الكمية',
            'unit_price' => 'سعر الكارتون',
            'discount_pct' => 'خصم %',
            'total' => 'المجموع',
            'discount' => 'الخصم',
            'original' => 'الأصلي',
            'net' => 'الصافي',
            'invoice_total' => 'مجموع الفاتورة',
            'due_amount' => 'الرصيد السابق',
            'grand_total' => 'المجموع الكلي',
            'total_quantity' => 'إجمالي الكمية',
            'invoice_description' => 'ملاحظة',
            'company_phone' => 'هاتف الشركة:',
            'company_address' => 'عنوان الشركة:',
        ]
        : [
            'title' => 'Sales Invoice',
            'invoice_no' => 'Invoice no:',
            'printed_at' => 'Printed at:',
            'created_at' => 'Created at:',
            'salesman_phone' => 'Salesman phone:',
            'client_phone' => 'Client phone:',
            'store' => 'Store:',
            'date' => 'Date:',
            'salesman' => 'Salesman:',
            'account' => 'Customer name:',
            'address' => 'Address:',
            'city' => 'City:',
            'weight' => 'Weight:',
            'item_code' => 'Item code',
            'item_name' => 'Item name',
            'quantity' => 'Quantity',
            'unit_price' => 'Carton price',
            'discount_pct' => 'خصم %',
            'total' => 'Total',
            'discount' => 'Discount',
            'original' => 'Original',
            'net' => 'Net',
            'invoice_total' => 'Invoice Total',
            'due_amount' => 'Due amount',
            'grand_total' => 'Grand total',
            'total_quantity' => 'Total quantity',
            'invoice_description' => 'Invoice description',
            'company_phone' => 'Company phone:',
            'company_address' => 'Company address:',
        ];
    if ($isRtlInvoice) {
        foreach ($labels as $key => $label) {
            $labels[$key] = Ar::glyphsKeepLatinDigits($label);
        }
    }
    $hasDiscountColumn = collect($items)->contains(
        static fn ($item): bool => abs((float) ($item->discount_percent ?? 0)) > 0.000001
    );
    $renderMixedText = static function (?string $value): string {
        $text = (string) ($value ?? '');
        return preg_match('/\p{Arabic}/u', $text) ? Ar::glyphsKeepLatinDigits($text) : $text;
    };
@endphp
<html lang="{{ $isRtlInvoice ? 'ar' : 'en' }}" dir="{{ $invoiceDirection }}">
<head>
    <meta charset="UTF-8">
    <style>
        @font-face {
            font-family: 'InvoiceNotoSans';
            font-style: normal;
            font-weight: 400;
            src: url('{{ public_path('fonts/NotoSans-Regular.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'InvoiceNotoNaskhArabic';
            font-style: normal;
            font-weight: 400;
            src: url('{{ public_path('fonts/NotoNaskhArabic-Regular.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'InvoiceNotoNaskhArabic';
            font-style: normal;
            font-weight: 700;
            src: url('{{ public_path('fonts/NotoNaskhArabic-Bold.ttf') }}') format('truetype');
        }
        @page { margin-top: 12px; }
        body {
            font-family: Arial, sans-serif;
            font-size: 13px;
            color: #000000;
            direction: {{ $invoiceDirection }};
            text-align: {{ $isRtlInvoice ? 'right' : 'left' }};
            margin: 0 14px 12px 14px;
        }
        * { font-family: Arial, sans-serif; }
        .print-stamp {
            direction: ltr;
            text-align: left;
            margin: 0;
            padding: 0;
            height: 13px;
            line-height: 13px;
            font-size: 11px;
            white-space: nowrap;
            overflow: hidden;
            font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif;
            color: #000000;
        }
        .top { width: 100%; margin-top: 0; margin-bottom: 2px; table-layout: fixed; padding-bottom: 2px; }
        .top td { vertical-align: middle; }
        .top-left { width: 19.1%; }
        .top-right { width: 19.1%; }
        .top-center { width: 61.8%; }
        .title {
            font-size: 21px; font-weight: 700; text-align: center; margin: 0;
            padding: 2px 8px; color: #000000; background: #ffffff; line-height: 1.05;
        }
        .header-separator { margin: 1px 0 2px; }
        .company { font-size: 24px; font-weight: 700; text-align: center; line-height: 1.05; color: #000000; margin: 0; padding: 0; }
        .invoice-title-inline {
            font-size: 21px;
            font-weight: 700;
            text-align: center;
            line-height: 1.05;
            color: #000000;
            margin: 0;
            padding: 0;
            font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif;
        }
        .small { font-size: 12px; color: #000000; line-height: 1; font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; }
        .logo { max-height: 96px; max-width: 240px; width: auto; height: auto; object-fit: contain; display: block; }
        .meta-grid { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 2px; direction: ltr; }
        .meta-grid td { padding: 1px 6px; vertical-align: middle; line-height: 1.05; color: #000000; font-size: 12px; }
        .meta-grid .meta-label { width: 10%; font-weight: 700; white-space: nowrap; text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; }
        .meta-grid .meta-value { width: 31%; unicode-bidi: plaintext; text-align: right; }
        .meta-grid .meta-left-value { width: 21%; text-align: right; padding-left: 2px; padding-right: 4px; }
        .meta-grid .meta-left-label { width: 7%; text-align: right; padding-left: 0; padding-right: 4px; }
        .title, .meta-label, .items th, .label, .arabic, .arabic-cell {
            font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif;
        }
        .label { font-weight: 700; text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; white-space: nowrap; color: #000000; }
        .value { text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; unicode-bidi: plaintext; color: #000000; }
        .items { width: 100%; border-collapse: collapse; margin-top: 3px; table-layout: auto; direction: {{ $invoiceDirection }}; }
        .items th, .items td { border: 1px solid #e2e8f0; padding: 2px 5px; color: #0f172a; font-size: 12px; line-height: 1.15; }
        .items td.col-code,
        .items td.col-name,
        .items td.col-qty,
        .items td.col-unit,
        .items td.col-discount,
        .items td.col-total { padding-top: 1px; padding-bottom: 1px; }
        .items th { background: #eef2ff; color: #3730a3; font-weight: 700; border-color: #c7d2fe; }
        .items tbody tr:nth-child(even) td { background: #fafafa; }
        .items tfoot td { font-weight: 700; background: #f1f5f9; border-top: 2px solid #4f46e5; }
        .items tfoot td:not(.empty-cell) { background: #f1f5f9; }
        .items tfoot td.empty-cell { border: none; background: transparent; }
        .items .col-idx { width: 4%; }
        .items .col-code { width: 13%; }
        .items .col-name { width: 30%; }
        .items .col-qty { width: 7%; }
        .items .col-unit { width: 12%; }
        .items .col-discount { width: 9%; }
        .items .col-total { width: 18%; }
        .num { text-align: right; direction: ltr; unicode-bidi: embed; }
        .currency-label { font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; direction: rtl; unicode-bidi: isolate; }
        .center { text-align: center; }
        .items th, .items td { white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
        .items .col-code, .items .col-qty, .items .col-unit, .items .col-discount, .items .col-total { white-space: nowrap; }
        .items .col-name { white-space: normal; line-height: 1.2; }
        @if ($isRtlInvoice)
        .items th, .items td { text-align: right; }
        .items .center, .items .num { text-align: right; }
        @endif
        .footer {
            position: static;
            width: 100%;
            margin-top: 4px;
        }
        .footer-box { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .footer-box td { border: 1px solid #000000; padding: 3px 6px; height: 22px; vertical-align: middle; font-size: 12px; line-height: 1.15; color: #000000; font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; }
        .footer-box .num { width: 16%; text-align: right; white-space: nowrap; }
        .footer-box .lbl { width: 14%; font-weight: 700; text-align: right; white-space: nowrap; }
        .footer-box .val { width: 14%; text-align: right; white-space: nowrap; }
        .footer-box .note-title, .footer-box .note-body { width: 42%; text-align: right; }
        .footer-box .note-body { vertical-align: top; }
        .footer-box .sales { background: #fef2f2; color: #7f1d1d; font-weight: 700; }
        .footer-box .grand td, .footer-box tr.grand td { font-weight: 700; }
        .footer-box .legal { height: auto; font-size: 9px; line-height: 1.25; text-align: right; padding: 3px 6px; }
        .note-text { direction: {{ $isRtlInvoice ? 'rtl' : 'ltr' }}; unicode-bidi: plaintext; font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; }
        .footer-meta { border-collapse: collapse; margin-top: 4px; width: 100%; table-layout: auto; }
        .footer-meta td { padding: 2px 8px 2px 0; height: auto; font-size: 10px; vertical-align: middle; color: #000000; line-height: 1.2; white-space: nowrap; font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; }
        .footer-meta .label { width: auto; white-space: nowrap; vertical-align: middle; font-size: 10px; font-weight: 400; line-height: 1.2; text-align: right; font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; }
        .footer-meta .value { width: auto; text-align: right; vertical-align: middle; font-size: 10px; font-weight: 400; line-height: 1.2; white-space: nowrap; font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; }
        .footer-meta .value .arabic { display: inline; max-width: none; white-space: nowrap; }
        .arabic { direction: rtl; unicode-bidi: isolate; text-align: right; display: inline-block; max-width: 100%; }
        .address-value-text { font-size: 12px; }
    </style>
</head>
<body>
    <div class="print-stamp">{{ e(now()->format('Y/m/d')) }} {{ $labels['printed_at'] }}</div>
    <table class="top">
        <tr>
            <td class="top-left"></td>
            <td class="top-center">
                <div class="company"><span class="arabic">{{ $renderMixedText((string) ($branding['company_name'] ?? 'N.Z.Y. Company')) }}</span></div>
                <div class="invoice-title-inline">{{ $labels['title'] }}</div>
            </td>
            <td class="small top-right" style="text-align:right;">
                @if (!empty($brandingLogoDataUri))
                    <img class="logo" src="{{ $brandingLogoDataUri }}" alt="logo">
                @endif
            </td>
        </tr>
    </table>

    <div class="header-separator"></div>

    <table class="meta-grid">
        <tr>
            <td class="meta-value meta-left-value">{{ e((string) ($invoice->client_phone ?? '')) }}</td>
            <td class="meta-label meta-left-label">{{ $labels['client_phone'] }}</td>
            <td class="meta-value">{{ e((string) ($invoice->invoice_date ?? '')) }}</td>
            <td class="meta-label">{{ $labels['date'] }}</td>
        </tr>
        <tr>
            <td class="meta-value meta-left-value"><span class="arabic">{{ e($renderMixedText((string) ($invoice->city_name ?? ''))) }}</span></td>
            <td class="meta-label meta-left-label">{{ $labels['city'] }}</td>
            <td class="meta-value">{{ e((string) ($invoice->invoice_no ?? $invoice->invoice_id ?? '')) }}</td>
            <td class="meta-label">{{ $labels['invoice_no'] }}</td>
        </tr>
        <tr>
            <td class="meta-value meta-left-value"><span class="arabic">{{ e($renderMixedText((string) ($invoice->store_name ?? ''))) }}</span></td>
            <td class="meta-label meta-left-label">{{ $labels['store'] }}</td>
            <td class="meta-value"><span class="arabic">{{ e($renderMixedText((string) ($invoice->client_name ?? ''))) }}</span></td>
            <td class="meta-label">{{ $labels['account'] }}</td>
        </tr>
        <tr>
            <td class="meta-value">&nbsp;</td>
            <td class="meta-label">&nbsp;</td>
            <td class="meta-value"><span class="arabic address-value-text">{{ e($renderMixedText((string) ($invoice->client_address ?? ''))) }}</span></td>
            <td class="meta-label">{{ $labels['address'] }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
        <tr>
            @if ($isRtlInvoice)
                <th class="num col-total">{{ $labels['total'] }}</th>
                @if ($hasDiscountColumn)
                    <th class="num col-discount">{{ $labels['discount_pct'] }}</th>
                @endif
                <th class="num col-unit">{{ $labels['unit_price'] }}</th>
                <th class="num col-qty">{{ $labels['quantity'] }}</th>
                <th class="col-name">{{ $labels['item_name'] }}</th>
                <th class="col-code">{{ $labels['item_code'] }}</th>
                <th class="center col-idx">#</th>
            @else
                <th class="center col-idx">#</th>
                <th class="col-code">{{ $labels['item_code'] }}</th>
                <th class="col-name">{{ $labels['item_name'] }}</th>
                <th class="num col-qty">{{ $labels['quantity'] }}</th>
                <th class="num col-unit">{{ $labels['unit_price'] }}</th>
                @if ($hasDiscountColumn)
                    <th class="num col-discount">{{ $labels['discount_pct'] }}</th>
                @endif
                <th class="num col-total">{{ $labels['total'] }}</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @php
            $sumQuantity = 0.0;
            $sumGross = 0.0;
            $sumNet = 0.0;
            $sumWeight = 0.0;
        @endphp
        @foreach ($items as $index => $item)
            @php
                $qty = (float) ($item->quantity ?? 0);
                $amount = (float) ($item->amount ?? 0);
                $grossAmount = (float) ($item->gross_amount ?? $amount);
                $discountPercent = (float) ($item->discount_percent ?? 0);
                $unitPrice = $qty !== 0.0 ? $grossAmount / $qty : 0.0;
                $lineGross = (float) round($grossAmount);
                $lineNet = abs($discountPercent) > 0.000001 ? (float) round($amount) : $lineGross;
                $weight = (float) ($item->weight_total ?? $item->item_weight_total ?? $item->weight ?? 0);
                $sumQuantity += $qty;
                $sumGross += $lineGross;
                $sumNet += $lineNet;
                $sumWeight += $weight;
            @endphp
            <tr>
                @if ($isRtlInvoice)
                    <td class="num col-total">{{ display_number($lineGross) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    @if ($hasDiscountColumn)
                        <td class="num col-discount">{{ display_number($discountPercent) }}%</td>
                    @endif
                    <td class="num col-unit">{{ display_number($unitPrice) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    <td class="num col-qty">{{ display_number($qty) }}</td>
                    <td class="col-name"><span class="arabic">{{ $renderMixedText((string) ($item->item_name ?? '')) }}</span></td>
                    <td class="num col-code">{{ $item->item_code ?? '' }}</td>
                    <td class="center col-idx">{{ $index + 1 }}</td>
                @else
                    <td class="center col-idx">{{ $index + 1 }}</td>
                    <td class="num col-code">{{ $item->item_code ?? '' }}</td>
                    <td class="col-name"><span class="arabic">{{ $renderMixedText((string) ($item->item_name ?? '')) }}</span></td>
                    <td class="num col-qty">{{ display_number($qty) }}</td>
                    <td class="num col-unit">{{ display_number($unitPrice) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    @if ($hasDiscountColumn)
                        <td class="num col-discount">{{ display_number($discountPercent) }}%</td>
                    @endif
                    <td class="num col-total">{{ display_number($lineGross) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                @endif
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            @if ($isRtlInvoice)
                <td class="num col-total">{{ display_number($sumGross) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                @if ($hasDiscountColumn)
                    <td class="col-discount empty-cell"></td>
                @endif
                <td class="col-unit empty-cell"></td>
                <td class="num col-qty">{{ display_number($sumQuantity) }}</td>
                <td class="col-name arabic-cell">{{ $labels['total'] }}</td>
                <td class="col-code empty-cell"></td>
                <td class="col-idx empty-cell"></td>
            @else
                <td class="col-idx empty-cell"></td>
                <td class="col-code empty-cell"></td>
                <td class="col-name arabic-cell">{{ $labels['total'] }}</td>
                <td class="num col-qty">{{ display_number($sumQuantity) }}</td>
                <td class="col-unit empty-cell"></td>
                @if ($hasDiscountColumn)
                    <td class="col-discount empty-cell"></td>
                @endif
                <td class="num col-total">{{ display_number($sumGross) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
            @endif
        </tr>
        </tfoot>
    </table>

    @php
        $invoiceTotal = $sumNet;
        $dueAmount = (float) ($invoice->client_due_amount ?? 0);
        $grandTotal = $invoiceTotal + $dueAmount;
        $invoiceWeightTotal = (float) ($invoice->invoice_weight ?? $invoice->total_weight ?? $invoice->weight_total ?? $sumWeight ?? 0);
    @endphp
    <div class="footer">
        <table class="footer-box">
            @if ($isRtlInvoice)
                <tr>
                    <td class="num">{{ display_number($invoiceTotal) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    <td class="lbl">{{ $labels['invoice_total'] }}</td>
                    <td class="val sales"><span class="arabic">{{ $renderMixedText((string) ($invoice->salesman_name ?? '')) }}</span></td>
                    <td class="lbl sales">{{ $labels['salesman'] }}</td>
                    <td class="note-title">{{ $labels['invoice_description'] }}</td>
                </tr>
                <tr>
                    <td class="num">{{ display_number($dueAmount) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    <td class="lbl">{{ $labels['due_amount'] }}</td>
                    <td class="val sales">{{ (string) ($invoice->salesman_phone ?? '') }}</td>
                    <td class="lbl sales">{{ $labels['salesman_phone'] }}</td>
                    <td class="note-body" rowspan="2"><span class="note-text">{{ $renderMixedText((string) ($invoice->invoice_desc ?? '')) }}</span></td>
                </tr>
                <tr class="grand">
                    <td class="num">{{ display_number($grandTotal) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    <td class="lbl">{{ $labels['grand_total'] }}</td>
                    <td class="val">{{ display_number($invoiceWeightTotal) }}</td>
                    <td class="lbl">{{ $labels['weight'] }}</td>
                </tr>
            @else
                <tr>
                    <td class="lbl">{{ $labels['invoice_total'] }}</td>
                    <td class="num">{{ display_number($invoiceTotal) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    <td class="lbl sales">{{ $labels['salesman'] }}</td>
                    <td class="val sales"><span class="arabic">{{ $renderMixedText((string) ($invoice->salesman_name ?? '')) }}</span></td>
                    <td class="note-title">{{ $labels['invoice_description'] }}</td>
                </tr>
                <tr>
                    <td class="lbl">{{ $labels['due_amount'] }}</td>
                    <td class="num">{{ display_number($dueAmount) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    <td class="lbl sales">{{ $labels['salesman_phone'] }}</td>
                    <td class="val sales">{{ (string) ($invoice->salesman_phone ?? '') }}</td>
                    <td class="note-body" rowspan="2"><span class="note-text">{{ $renderMixedText((string) ($invoice->invoice_desc ?? '')) }}</span></td>
                </tr>
                <tr class="grand">
                    <td class="lbl">{{ $labels['grand_total'] }}</td>
                    <td class="num">{{ display_number($grandTotal) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    <td class="lbl">{{ $labels['weight'] }}</td>
                    <td class="val">{{ display_number($invoiceWeightTotal) }}</td>
                </tr>
            @endif
            <tr>
                <td class="legal" colspan="5"><span class="arabic">{{ $renderMixedText((string) ($branding['footer_note'] ?? '')) }}</span></td>
            </tr>
        </table>
        <table class="footer-meta">
            @if ($isRtlInvoice)
                <tr>
                    <td class="value">{{ $branding['company_mobile'] ?? '' }}</td>
                    <td class="label">{{ $labels['company_phone'] }}</td>
                    <td class="value"><span class="arabic">{{ $renderMixedText((string) ($branding['company_address'] ?? '')) }}</span></td>
                    <td class="label">{{ $labels['company_address'] }}</td>
                    <td class="value">{{ e((string) ($invoice->created_at ?? '')) }}</td>
                    <td class="label">{{ $labels['created_at'] }}</td>
                </tr>
            @else
                <tr>
                    <td class="label">{{ $labels['created_at'] }}</td>
                    <td class="value">{{ e((string) ($invoice->created_at ?? '')) }}</td>
                    <td class="label">{{ $labels['company_phone'] }}</td>
                    <td class="value">{{ $branding['company_mobile'] ?? '' }}</td>
                    <td class="label">{{ $labels['company_address'] }}</td>
                    <td class="value"><span class="arabic">{{ $renderMixedText((string) ($branding['company_address'] ?? '')) }}</span></td>
                </tr>
            @endif
        </table>
    </div>
</body>
</html>

