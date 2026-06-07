<!DOCTYPE html>
@php
    $invoiceDirection = (($branding['invoice_direction'] ?? 'ltr') === 'rtl') ? 'rtl' : 'ltr';
    $isRtlInvoice = $invoiceDirection === 'rtl';
    $currencyLabel = $isRtlInvoice ? 'د.ع' : 'IQD';
    $labels = $isRtlInvoice
        ? [
            'print' => 'طباعة',
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
            'print' => 'Print',
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
    $kvRow = static function (bool $rtl, string $label, string $valueHtml, bool $numericValue = false): string {
        $valueClass = $numericValue ? 'value num' : 'value';
        return $rtl
            ? '<tr><td class="'.$valueClass.'">'.$valueHtml.'</td><td class="label">'.$label.'</td></tr>'
            : '<tr><td class="label">'.$label.'</td><td class="'.$valueClass.'">'.$valueHtml.'</td></tr>';
    };
@endphp
<html lang="{{ $isRtlInvoice ? 'ar' : 'en' }}" dir="{{ $invoiceDirection }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $labels['title'] }} {{ $invoice->invoice_no ?? '' }}</title>
    <style>
        @font-face {
            font-family: 'InvoiceNotoSans';
            font-style: normal;
            font-weight: 400;
            src: url('{{ public_path('fonts/NotoSans-Regular.ttf') }}') format('truetype'),
                 url('/fonts/NotoSans-Regular.ttf') format('truetype');
        }
        @font-face {
            font-family: 'InvoiceNotoSansArabic';
            font-style: normal;
            font-weight: 400;
            src: url('{{ public_path('fonts/NotoSansArabic-Regular.ttf') }}') format('truetype'),
                 url('/fonts/NotoSansArabic-Regular.ttf') format('truetype');
        }
        @font-face {
            font-family: 'InvoiceTajawal';
            font-style: normal;
            font-weight: 400;
            src: url('{{ public_path('fonts/Tajawal-Regular.ttf') }}') format('truetype'),
                 url('/fonts/Tajawal-Regular.ttf') format('truetype');
        }
        body { font-family: 'InvoiceTajawal', 'InvoiceNotoSansArabic', 'InvoiceNotoSans', "Noto Sans Arabic", "Noto Sans", Arial, sans-serif; margin: 14px; color: #111; direction: {{ $invoiceDirection }}; text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; unicode-bidi: embed; font-size: 14px; }
        * { font-family: 'InvoiceTajawal', 'InvoiceNotoSansArabic', 'InvoiceNotoSans', "Noto Sans Arabic", "Noto Sans", Arial, sans-serif; }
        .print-btn { margin-bottom: 8px; padding: 8px 12px; border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; cursor: pointer; }
        .page-number { position: fixed; top: 6px; left: 10px; font-size: 10px; color: #4b5563; }
        .top { width: 100%; margin-bottom: 3px; direction: ltr; table-layout: fixed; }
        .top td { vertical-align: top; }
        .company { font-size: 20px; font-weight: 700; text-align: center; line-height: 1.05; }
        .logo { width: 92px; height: 92px; object-fit: contain; display: block; }
        .title { font-size: 18px; font-weight: 700; margin-bottom: 3px; text-align: center; line-height: 1.1; }
        .small { color: #374151; font-size: 13px; line-height: 1; }
        .meta-layout { width: 100%; border-collapse: collapse; margin-bottom: 3px; direction: {{ $invoiceDirection }}; table-layout: fixed; }
        .meta-layout > tbody > tr > td { width: 50%; vertical-align: top; }
        .meta-table { width: 100%; border-collapse: collapse; direction: {{ $invoiceDirection }}; }
        .meta-table td { padding: 2px 4px; font-size: 14px; vertical-align: top; line-height: 1.15; }
        .label { font-weight: 700; white-space: nowrap; }
        .meta-table .label { width: 34%; }
        .meta-table .value { width: 66%; text-align: left; }
        .address-value-text { font-size: 12px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; direction: {{ $invoiceDirection }}; }
        .items th, .items td { border: 1px solid #cfcfcf; padding: 6px; font-size: 14px; }
        .items th { background: #f3f4f6; }
        .items tfoot td { font-weight: 700; background: #fafafa; }
        .items .col-idx { width: 3%; }
        .items .col-code { width: {{ $isRtlInvoice ? '14%' : '10%' }}; }
        .items .col-name { width: {{ $isRtlInvoice ? '35%' : '41%' }}; }
        .items .col-qty { width: 6%; }
        .items .col-unit { width: 12%; }
        .items .col-discount { width: 10%; }
        .items .col-total { width: 20%; }
        .center { text-align: center; }
        .num { text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; direction: ltr; unicode-bidi: embed; }
        @if ($isRtlInvoice)
        .items th, .items td { text-align: right; }
        .items .center, .items .num { text-align: right; }
        @endif
        .footer { margin-top: 8px; }
        .footer-panels { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .footer-panels > tbody > tr > td { width: 50%; vertical-align: top; }
        .footer-panels > tbody > tr > td:first-child { width: 35%; }
        .footer-panels > tbody > tr > td:last-child { width: 65%; }
        .total-box { width: 98%; border-collapse: collapse; margin-right: auto; }
        .total-box td { border: 1px solid #cfcfcf; padding: 6px; }
        .total-box .salesman-gap td { border: none; padding: 0; height: 8px; background: transparent; }
        .total-box .salesman-highlight td { background: #fef2f2; color: #7f1d1d; font-weight: 700; }
        .desc-box { width: 100%; border-collapse: collapse; margin-left: auto; }
        .desc-box td { border: 1px solid #cfcfcf; padding: 6px; }
        .desc-box .desc-content { height: 72px; vertical-align: top; white-space: pre-wrap; text-align: right; direction: {{ $isRtlInvoice ? 'rtl' : 'ltr' }}; unicode-bidi: plaintext; }
        .desc-box .desc-footer { font-size: 10px; line-height: 1.3; text-align: right; direction: {{ $isRtlInvoice ? 'rtl' : 'ltr' }}; unicode-bidi: plaintext; }
        .note { margin-top: 8px; font-size: 12px; color: #333; }
        .footer-meta { border-collapse: collapse; margin-top: 6px; width: 100%; max-width: 720px; }
        .footer-meta td { padding: 2px 4px; height: 20px; font-size: 10px; vertical-align: middle; line-height: 1.25; }
        .footer-meta .label { width: 14%; white-space: nowrap; vertical-align: middle; font-size: 10px; font-weight: 400; line-height: 1.25; }
        .footer-meta .value { width: 36%; text-align: right; vertical-align: middle; font-size: 10px; font-weight: 400; line-height: 1.25; }
        @media print { .print-btn { display: none; } body { margin: 8px; } }
    </style>
</head>
<body>
    <button class="print-btn" type="button" onclick="window.print()" title="{{ $labels['print'] ?? 'Print' }}" aria-label="{{ $labels['print'] ?? 'Print' }}">{{ $labels['print'] ?? 'Print' }}</button>
    <div class="page-number">Page 1</div>

    <table class="top">
        <tr>
            <td class="small num">{{ now()->format('d.m.Y') }}</td>
            <td class="company">{{ $branding['company_name'] ?? 'N.Z.Y. Company' }}</td>
            <td class="small" style="text-align:right;">
                @if (!empty($brandingLogoDataUri))
                    <img src="{{ $brandingLogoDataUri }}" class="logo" alt="logo">
                @endif
            </td>
        </tr>
    </table>

    <div class="title">{{ $labels['title'] }}</div>
    <table class="meta-layout">
        <tr>
            <td>
                <table class="meta-table">
                    {!! $kvRow($isRtlInvoice, $labels['account'], e((string) ($invoice->client_name ?? ''))) !!}
                    {!! $kvRow($isRtlInvoice, $labels['address'], '<span class="address-value-text">'.e((string) ($invoice->client_address ?? '')).'</span>') !!}
                    {!! $kvRow($isRtlInvoice, $labels['client_phone'], e((string) ($invoice->client_phone ?? '')), true) !!}
                    {!! $kvRow($isRtlInvoice, $labels['city'], e((string) ($invoice->city_name ?? ''))) !!}
                    {!! $kvRow($isRtlInvoice, $labels['store'], e((string) ($invoice->store_name ?? ''))) !!}
                </table>
            </td>
            <td>
                <table class="meta-table">
                    {!! $kvRow($isRtlInvoice, $labels['date'], e((string) ($invoice->invoice_date ?? '')), true) !!}
                    {!! $kvRow($isRtlInvoice, $labels['invoice_no'], e((string) ($invoice->invoice_no ?? $invoice->invoice_id ?? '')), true) !!}
                    {!! $kvRow($isRtlInvoice, $labels['printed_at'], e(now()->format('Y/m/d')), true) !!}
                    {!! $kvRow($isRtlInvoice, $labels['created_at'], e((string) ($invoice->created_at ?? '')), true) !!}
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
        <tr>
            @if ($isRtlInvoice)
                <th class="num col-total">{{ $labels['total'] }}</th>
                <th class="num col-discount">{{ $labels['discount_pct'] }}</th>
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
                <th class="num col-discount">{{ $labels['discount_pct'] }}</th>
                <th class="num col-total">{{ $labels['total'] }}</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @php
            $sumQuantity = 0.0;
            $sumAmount = 0.0;
        @endphp
        @foreach ($items as $index => $item)
            @php
                $qty = (float) ($item->quantity ?? 0);
                $amount = (float) ($item->amount ?? 0);
                $grossAmount = (float) ($item->gross_amount ?? $amount);
                $discountPercent = (float) ($item->discount_percent ?? 0);
                $unitPrice = $qty !== 0.0 ? $grossAmount / $qty : 0.0;
                $sumQuantity += $qty;
                $sumAmount += $amount;
            @endphp
            <tr>
                @if ($isRtlInvoice)
                    <td class="num col-total">{{ display_number($amount) }} {{ $currencyLabel }}</td>
                    <td class="num col-discount">{{ display_number($discountPercent) }}%</td>
                    <td class="num col-unit">{{ display_number($unitPrice) }} {{ $currencyLabel }}</td>
                    <td class="num col-qty">{{ display_number($qty) }}</td>
                    <td class="num col-code">{{ $item->item_code ?? '' }}</td>
                    <td class="col-name">{{ $item->item_name ?? '' }}</td>
                    <td class="center col-idx">{{ $index + 1 }}</td>
                @else
                    <td class="center col-idx">{{ $index + 1 }}</td>
                    <td class="col-name">{{ $item->item_name ?? '' }}</td>
                    <td class="num col-code">{{ $item->item_code ?? '' }}</td>
                    <td class="num col-qty">{{ display_number($qty) }}</td>
                    <td class="num col-unit">{{ display_number($unitPrice) }} {{ $currencyLabel }}</td>
                    <td class="num col-discount">{{ display_number($discountPercent) }}%</td>
                    <td class="num col-total">{{ display_number($amount) }} {{ $currencyLabel }}</td>
                @endif
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            @if ($isRtlInvoice)
                <td class="num col-total">{{ display_number($sumAmount) }} {{ $currencyLabel }}</td>
                <td class="col-discount"></td>
                <td class="col-unit"></td>
                <td class="num col-qty">{{ display_number($sumQuantity) }}</td>
                <td class="col-code"></td>
                <td class="col-name">{{ $labels['total'] }}</td>
                <td class="col-idx"></td>
            @else
                <td class="col-idx"></td>
                <td class="col-name">{{ $labels['total'] }}</td>
                <td class="col-code"></td>
                <td class="num col-qty">{{ display_number($sumQuantity) }}</td>
                <td class="col-unit"></td>
                <td class="col-discount"></td>
                <td class="num col-total">{{ display_number($sumAmount) }} {{ $currencyLabel }}</td>
            @endif
        </tr>
        </tfoot>
    </table>

    @php
        $invoiceTotal = (float) ($invoice->invoice_amount ?? 0);
        $dueAmount = (float) ($invoice->client_due_amount ?? 0);
        $grandTotal = $invoiceTotal + $dueAmount;
    @endphp
    <div class="footer">
        <table class="footer-panels">
            <tr>
                <td>
                    <table class="total-box">
                        @if ($isRtlInvoice)
                            <tr><td class="num">{{ display_number($invoiceTotal) }} {{ $currencyLabel }}</td><td class="label">{{ $labels['invoice_total'] }}</td></tr>
                            <tr><td class="num">{{ display_number($dueAmount) }} {{ $currencyLabel }}</td><td class="label">{{ $labels['due_amount'] }}</td></tr>
                            <tr><td class="num">{{ display_number($grandTotal) }} {{ $currencyLabel }}</td><td class="label">{{ $labels['grand_total'] }}</td></tr>
                            <tr class="salesman-gap"><td colspan="2"></td></tr>
                            <tr class="salesman-highlight"><td class="num">{{ (string) ($invoice->salesman_name ?? '') }}</td><td class="label">{{ $labels['salesman'] }}</td></tr>
                            <tr class="salesman-highlight"><td class="num">{{ (string) ($invoice->salesman_phone ?? '') }}</td><td class="label">{{ $labels['salesman_phone'] }}</td></tr>
                        @else
                            <tr><td class="label">{{ $labels['invoice_total'] }}</td><td class="num">{{ display_number($invoiceTotal) }} {{ $currencyLabel }}</td></tr>
                            <tr><td class="label">{{ $labels['due_amount'] }}</td><td class="num">{{ display_number($dueAmount) }} {{ $currencyLabel }}</td></tr>
                            <tr><td class="label">{{ $labels['grand_total'] }}</td><td class="num">{{ display_number($grandTotal) }} {{ $currencyLabel }}</td></tr>
                            <tr class="salesman-gap"><td colspan="2"></td></tr>
                            <tr class="salesman-highlight"><td class="label">{{ $labels['salesman'] }}</td><td class="num">{{ (string) ($invoice->salesman_name ?? '') }}</td></tr>
                            <tr class="salesman-highlight"><td class="label">{{ $labels['salesman_phone'] }}</td><td class="num">{{ (string) ($invoice->salesman_phone ?? '') }}</td></tr>
                        @endif
                    </table>
                </td>
                <td>
                    <table class="desc-box">
                        <tr><td class="label">{{ $labels['invoice_description'] }}</td></tr>
                        <tr><td class="desc-content">{{ $invoice->invoice_desc ?? '' }}</td></tr>
                        <tr><td class="desc-footer">{{ $branding['footer_note'] ?? '' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
        <table class="footer-meta">
            @if ($isRtlInvoice)
                <tr>
                    <td class="value num">{{ $branding['company_mobile'] ?? '' }}</td>
                    <td class="label">{{ $labels['company_phone'] }}</td>
                    <td class="value">{{ $branding['company_address'] ?? '' }}</td>
                    <td class="label">{{ $labels['company_address'] }}</td>
                </tr>
            @else
                <tr>
                    <td class="label">{{ $labels['company_phone'] }}</td>
                    <td class="value num">{{ $branding['company_mobile'] ?? '' }}</td>
                    <td class="label">{{ $labels['company_address'] }}</td>
                    <td class="value">{{ $branding['company_address'] ?? '' }}</td>
                </tr>
            @endif
        </table>
    </div>
    <script>
        (function () {
            var pageNode = document.querySelector('.page-number');
            if (!pageNode) return;
            pageNode.textContent = 'Page 1';
        })();
    </script>
</body>
</html>

