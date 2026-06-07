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
        body {
            font-family: Arial, sans-serif;
            font-size: 13px;
            color: #000000;
            direction: {{ $invoiceDirection }};
            text-align: {{ $isRtlInvoice ? 'right' : 'left' }};
            margin: 12px 14px 235px 14px;
        }
        * { font-family: Arial, sans-serif; }
        .page-number { position: fixed; top: 8px; left: 12px; font-size: 10px; color: #000000; }
        .top { width: 100%; margin-top: -6px; margin-bottom: 2px; table-layout: fixed; padding-bottom: 2px; }
        .top td { vertical-align: middle; }
        .top-left, .top-right { width: 19.1%; }
        .top-center { width: 61.8%; }
        .title {
            font-size: 21px; font-weight: 700; text-align: center; margin: 0;
            padding: 2px 8px; color: #000000; background: #ffffff; line-height: 1.05;
        }
        .header-separator { margin: 2px 0 4px; }
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
        .meta-grid { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 4px; direction: ltr; }
        .meta-grid td { padding: 2px 6px; vertical-align: middle; line-height: 1.1; color: #000000; font-size: 12px; }
        .meta-grid .meta-label { width: 10%; font-weight: 700; white-space: nowrap; text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; }
        .meta-grid .meta-value { width: 31%; unicode-bidi: plaintext; text-align: right; }
        .meta-grid .meta-left-value { width: 21%; text-align: right; padding-left: 2px; padding-right: 4px; }
        .meta-grid .meta-left-label { width: 7%; text-align: right; padding-left: 0; padding-right: 4px; }
        .title, .meta-label, .items th, .label, .arabic, .arabic-cell {
            font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif;
        }
        .label { font-weight: 700; text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; white-space: nowrap; color: #000000; }
        .value { text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; unicode-bidi: plaintext; color: #000000; }
        .items { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: auto; direction: {{ $invoiceDirection }}; }
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
            position: fixed;
            left: 14px;
            right: 14px;
            bottom: 12px;
            margin-top: 0;
        }
        .footer-panels { width: 100%; border-collapse: collapse; table-layout: fixed; border: 1px solid #000000; }
        .footer-panels > tbody > tr > td { vertical-align: top; padding: 0; }
        .footer-total-cell { width: 31%; }
        .footer-sales-cell { width: 27%; border-left: 1px solid #000000; border-right: 1px solid #000000; }
        .footer-desc-cell { width: 42%; }
        .total-box { width: 100%; border-collapse: collapse; }
        .total-box td { border: 1px solid #000000; padding: 4px 6px; color: #000000; font-size: 12px; line-height: 1.1; }
        .total-box tr:first-child td { background: #ffffff; font-weight: 700; border-top: none; }
        .footer-total-cell .total-box tr:last-child td { border-bottom: none; }
        .footer-total-cell .total-box td:first-child { border-left: none; }
        .footer-total-cell .total-box td:last-child { border-right: none; }
        .sales-box { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .sales-box td { border: 1px solid #000000; padding: 4px 5px; color: #7f1d1d; font-size: 12px; font-weight: 700; background: #fef2f2; overflow: hidden; line-height: 1.1; }
        .sales-box .label { width: 42%; white-space: normal; word-break: break-word; overflow-wrap: anywhere; text-align: right; }
        .sales-box .value, .sales-box .num { width: 58%; text-align: right; }
        .sales-box .weight-row td { background: #ffffff; color: #000000; }
        .footer-sales-cell .sales-box tr:first-child td { border-top: none; }
        .footer-sales-cell .sales-box tr:last-child td { border-bottom: none; }
        .footer-sales-cell .sales-box td:first-child { border-left: none; }
        .footer-sales-cell .sales-box td:last-child { border-right: none; }
        .desc-box { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .desc-box td { border: none; padding: 5px; color: #000000; font-size: 12px; }
        .desc-box .label { background: #ffffff; width: auto; padding-top: 3px; padding-bottom: 3px; line-height: 1.1; }
        .desc-box .desc-content { height: 44px; vertical-align: top; line-height: 1.25; text-align: right; }
        .desc-box .desc-content .note-text { display: block; direction: {{ $isRtlInvoice ? 'rtl' : 'ltr' }}; unicode-bidi: plaintext; text-align: inherit; font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; }
        .desc-box .desc-footer { font-size: 9px; line-height: 1.15; text-align: right; padding-top: 2px; padding-bottom: 2px; }
        .note { margin-top: 8px; font-size: 12px; color: #000000; text-align: {{ $isRtlInvoice ? 'right' : 'right' }}; unicode-bidi: plaintext; line-height: 1.45; }
        .footer-meta { border-collapse: collapse; margin-top: 0; width: 100%; table-layout: fixed; border-top: none; }
        .footer-meta td { padding: 2px 4px; height: 20px; font-size: 10px; vertical-align: middle; color: #000000; line-height: 1.25; }
        .footer-meta .label { width: 14%; white-space: nowrap; vertical-align: middle; font-size: 10px; font-weight: 400; line-height: 1.25; font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; }
        .footer-meta .value { width: 36%; text-align: right; vertical-align: middle; font-size: 10px; font-weight: 400; line-height: 1.25; font-family: 'InvoiceNotoNaskhArabic', 'InvoiceNotoSans', sans-serif; }
        .arabic { direction: rtl; unicode-bidi: isolate; text-align: right; display: inline-block; max-width: 100%; }
        .address-value-text { font-size: 12px; }
    </style>
</head>
<body>
    <div class="page-number"></div>
    <table class="top">
        <tr>
            <td class="small num top-left">{{ e(now()->format('Y/m/d')) }} {{ $labels['printed_at'] }}</td>
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
                <th class="col-code">{{ $labels['item_code'] }}</th>
                <th class="col-name">{{ $labels['item_name'] }}</th>
                <th class="center col-idx">#</th>
            @else
                <th class="center col-idx">#</th>
                <th class="col-name">{{ $labels['item_name'] }}</th>
                <th class="col-code">{{ $labels['item_code'] }}</th>
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
            $sumAmount = 0.0;
            $sumWeight = 0.0;
        @endphp
        @foreach ($items as $index => $item)
            @php
                $qty = (float) ($item->quantity ?? 0);
                $amount = (float) ($item->amount ?? 0);
                $grossAmount = (float) ($item->gross_amount ?? $amount);
                $discountPercent = (float) ($item->discount_percent ?? 0);
                $unitPrice = $qty !== 0.0 ? $grossAmount / $qty : 0.0;
                $weight = (float) ($item->weight_total ?? $item->item_weight_total ?? $item->weight ?? $item->net_weight ?? 0);
                $sumQuantity += $qty;
                $sumAmount += $amount;
                $sumWeight += $weight;
            @endphp
            <tr>
                @if ($isRtlInvoice)
                    <td class="num col-total">{{ display_number($amount) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    @if ($hasDiscountColumn)
                        <td class="num col-discount">{{ display_number($discountPercent) }}%</td>
                    @endif
                    <td class="num col-unit">{{ display_number($unitPrice) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    <td class="num col-qty">{{ display_number($qty) }}</td>
                    <td class="num col-code">{{ $item->item_code ?? '' }}</td>
                    <td class="col-name"><span class="arabic">{{ $renderMixedText((string) ($item->item_name ?? '')) }}</span></td>
                    <td class="center col-idx">{{ $index + 1 }}</td>
                @else
                    <td class="center col-idx">{{ $index + 1 }}</td>
                    <td class="col-name"><span class="arabic">{{ $renderMixedText((string) ($item->item_name ?? '')) }}</span></td>
                    <td class="num col-code">{{ $item->item_code ?? '' }}</td>
                    <td class="num col-qty">{{ display_number($qty) }}</td>
                    <td class="num col-unit">{{ display_number($unitPrice) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                    @if ($hasDiscountColumn)
                        <td class="num col-discount">{{ display_number($discountPercent) }}%</td>
                    @endif
                    <td class="num col-total">{{ display_number($amount) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                @endif
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            @if ($isRtlInvoice)
                <td class="num col-total">{{ display_number($sumAmount) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
                @if ($hasDiscountColumn)
                    <td class="col-discount empty-cell"></td>
                @endif
                <td class="col-unit empty-cell"></td>
                <td class="num col-qty">{{ display_number($sumQuantity) }}</td>
                <td class="col-code empty-cell"></td>
                <td class="col-name arabic-cell">{{ $labels['total'] }}</td>
                <td class="col-idx empty-cell"></td>
            @else
                <td class="col-idx empty-cell"></td>
                <td class="col-name arabic-cell">{{ $labels['total'] }}</td>
                <td class="col-code empty-cell"></td>
                <td class="num col-qty">{{ display_number($sumQuantity) }}</td>
                <td class="col-unit empty-cell"></td>
                @if ($hasDiscountColumn)
                    <td class="col-discount empty-cell"></td>
                @endif
                <td class="num col-total">{{ display_number($sumAmount) }} <span class="currency-label">{{ $currencyLabel }}</span></td>
            @endif
        </tr>
        </tfoot>
    </table>

    @php
        $invoiceTotal = (float) ($invoice->invoice_amount ?? 0);
        $dueAmount = (float) ($invoice->client_due_amount ?? 0);
        $grandTotal = $invoiceTotal + $dueAmount;
        $invoiceWeightTotal = (float) ($invoice->invoice_weight ?? $invoice->total_weight ?? $invoice->weight_total ?? $sumWeight ?? 0);
    @endphp
    <div class="footer">
        <table class="footer-panels">
            <tr>
                <td class="footer-total-cell">
                    <table class="total-box">
                        @if ($isRtlInvoice)
                            <tr><td class="num">{{ display_number($invoiceTotal) }} <span class="currency-label">{{ $currencyLabel }}</span></td><td class="label">{{ $labels['invoice_total'] }}</td></tr>
                            <tr><td class="num">{{ display_number($dueAmount) }} <span class="currency-label">{{ $currencyLabel }}</span></td><td class="label">{{ $labels['due_amount'] }}</td></tr>
                            <tr><td class="num">{{ display_number($grandTotal) }} <span class="currency-label">{{ $currencyLabel }}</span></td><td class="label">{{ $labels['grand_total'] }}</td></tr>
                        @else
                            <tr><td class="label">{{ $labels['invoice_total'] }}</td><td class="num">{{ display_number($invoiceTotal) }} <span class="currency-label">{{ $currencyLabel }}</span></td></tr>
                            <tr><td class="label">{{ $labels['due_amount'] }}</td><td class="num">{{ display_number($dueAmount) }} <span class="currency-label">{{ $currencyLabel }}</span></td></tr>
                            <tr><td class="label">{{ $labels['grand_total'] }}</td><td class="num">{{ display_number($grandTotal) }} <span class="currency-label">{{ $currencyLabel }}</span></td></tr>
                        @endif
                    </table>
                </td>
                <td class="footer-sales-cell">
                    <table class="sales-box">
                        @if ($isRtlInvoice)
                            <tr><td class="value"><span class="arabic">{{ $renderMixedText((string) ($invoice->salesman_name ?? '')) }}</span></td><td class="label">{{ $labels['salesman'] }}</td></tr>
                            <tr><td class="num">{{ (string) ($invoice->salesman_phone ?? '') }}</td><td class="label">{{ $labels['salesman_phone'] }}</td></tr>
                            <tr class="weight-row"><td class="num">{{ display_number($invoiceWeightTotal) }}</td><td class="label">{{ $labels['weight'] }}</td></tr>
                        @else
                            <tr><td class="label">{{ $labels['salesman'] }}</td><td class="value"><span class="arabic">{{ $renderMixedText((string) ($invoice->salesman_name ?? '')) }}</span></td></tr>
                            <tr><td class="label">{{ $labels['salesman_phone'] }}</td><td class="num">{{ (string) ($invoice->salesman_phone ?? '') }}</td></tr>
                            <tr class="weight-row"><td class="label">{{ $labels['weight'] }}</td><td class="num">{{ display_number($invoiceWeightTotal) }}</td></tr>
                        @endif
                    </table>
                </td>
                <td class="footer-desc-cell">
                    <table class="desc-box">
                        <tr><td class="label">{{ $labels['invoice_description'] }}</td></tr>
                        <tr><td class="desc-content"><span class="note-text">{{ $renderMixedText((string) ($invoice->invoice_desc ?? '')) }}</span></td></tr>
                        <tr><td class="desc-footer"><span class="arabic">{{ $renderMixedText((string) ($branding['footer_note'] ?? '')) }}</span></td></tr>
                    </table>
                </td>
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

