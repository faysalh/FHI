<!DOCTYPE html>
@php
    $invoiceDirection = (($branding['invoice_direction'] ?? 'rtl') === 'ltr') ? 'ltr' : 'rtl';
    $isRtlInvoice = $invoiceDirection === 'rtl';
@endphp
<html lang="{{ $isRtlInvoice ? 'ar' : 'en' }}" dir="{{ $invoiceDirection }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة {{ $invoice->invoice_no ?? '' }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 14px; color: #111; direction: {{ $invoiceDirection }}; text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; unicode-bidi: embed; }
        .print-btn { margin-bottom: 8px; padding: 8px 12px; border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; cursor: pointer; }
        .top { width: 100%; margin-bottom: 8px; direction: {{ $invoiceDirection }}; }
        .top td { vertical-align: top; }
        .company { font-size: 16px; font-weight: 700; text-align: center; }
        .logo { width: 72px; height: 72px; object-fit: contain; display: block; }
        .title { font-size: 22px; font-weight: 700; margin-bottom: 8px; }
        .small { color: #374151; font-size: 12px; }
        .meta-layout { width: 100%; border-collapse: collapse; margin-bottom: 8px; direction: {{ $invoiceDirection }}; table-layout: fixed; }
        .meta-layout > tbody > tr > td { width: 50%; vertical-align: top; }
        .meta-table { width: 100%; border-collapse: collapse; direction: {{ $invoiceDirection }}; }
        .meta-table td { padding: 3px 4px; font-size: 13px; vertical-align: top; }
        .label { font-weight: 700; white-space: nowrap; }
        .meta-table .label { width: 34%; }
        .meta-table .value { width: 66%; text-align: left; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; direction: {{ $invoiceDirection }}; }
        .items th, .items td { border: 1px solid #cfcfcf; padding: 6px; font-size: 13px; }
        .items th { background: #f3f4f6; }
        .center { text-align: center; }
        .num { text-align: {{ $isRtlInvoice ? 'right' : 'left' }}; direction: ltr; unicode-bidi: embed; }
        .totals { margin-top: 8px; width: 420px; margin-right: auto; border-collapse: collapse; }
        .totals td { border: 1px solid #cfcfcf; padding: 6px; }
        .note { margin-top: 8px; font-size: 11px; color: #333; }
        .footer-meta { border-collapse: collapse; margin-top: 6px; width: 100%; max-width: 720px; }
        .footer-meta td { padding: 2px 4px; font-size: 11px; vertical-align: top; }
        .footer-meta .label { width: 16%; }
        .footer-meta .value { text-align: left; }
        @media print { .print-btn { display: none; } body { margin: 8px; } }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">طباعة</button>

    <table class="top">
        <tr>
            <td class="small">
                @if (!empty($brandingLogoDataUri))
                    <img src="{{ $brandingLogoDataUri }}" class="logo" alt="logo">
                @endif
            </td>
            <td class="company">{{ $branding['company_name'] ?? 'N.Z.Y. Company' }}</td>
            <td class="small num">{{ now()->format('d.m.Y') }}</td>
        </tr>
    </table>

    <div class="title">فاتورة بيع</div>
    <table class="meta-layout">
        <tr>
            <td>
                <table class="meta-table">
                    <tr><td class="label">رقم الفاتورة:</td><td class="value num">{{ $invoice->invoice_no ?? $invoice->invoice_id ?? '' }}</td></tr>
                    <tr><td class="label">تاريخ الطباعة:</td><td class="value num">{{ now()->format('Y/m/d') }}</td></tr>
                    <tr><td class="label">وقت الإنشاء (PDA):</td><td class="value num">{{ $invoice->created_at ?? '' }}</td></tr>
                    <tr><td class="label">رقم المندوب:</td><td class="value num">{{ $invoice->salesman_phone ?? '' }}</td></tr>
                    <tr><td class="label">الهاتف:</td><td class="value num">{{ $invoice->client_phone ?? '' }}</td></tr>
                    <tr><td class="label">المستودع:</td><td class="value">{{ $invoice->store_name ?? '' }}</td></tr>
                </table>
            </td>
            <td>
                <table class="meta-table">
                    <tr><td class="label">التاريخ:</td><td class="value num">{{ $invoice->invoice_date ?? '' }}</td></tr>
                    <tr><td class="label">المندوب:</td><td class="value">{{ $invoice->salesman_name ?? '' }}</td></tr>
                    <tr><td class="label">الحساب:</td><td class="value">{{ $invoice->client_name ?? '' }} ({{ $invoice->client_code ?? '' }})</td></tr>
                    <tr><td class="label">العنوان:</td><td class="value">{{ $invoice->client_address ?? '' }}</td></tr>
                    <tr><td class="label">المدينة:</td><td class="value">{{ $invoice->city_name ?? '' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
        <tr>
            <th class="center">#</th>
            <th>كود المادة</th>
            <th>اسم المادة</th>
            <th class="num">الكمية</th>
            <th class="num">سعر الوحدة</th>
            <th class="num">المجموع</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($items as $index => $item)
            @php
                $qty = (float) ($item->quantity ?? 0);
                $amount = (float) ($item->amount ?? 0);
                $unitPrice = $qty !== 0.0 ? $amount / $qty : 0.0;
            @endphp
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td class="num">{{ $item->item_code ?? '' }}</td>
                <td>{{ $item->item_name ?? '' }}</td>
                <td class="num">{{ display_number($qty) }}</td>
                <td class="num">{{ display_number($unitPrice) }} د.ع</td>
                <td class="num">{{ display_number($amount) }} د.ع</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="label">المجموع النهائي</td><td class="num">{{ display_number((float) ($invoice->invoice_amount ?? 0)) }} د.ع</td></tr>
        <tr><td class="label">الرصيد السابق</td><td class="num">{{ display_number((float) ($invoice->client_due_amount ?? 0)) }} د.ع</td></tr>
        <tr><td class="label">إجمالي الكمية</td><td class="num">{{ display_number((float) ($invoice->quantity_total ?? 0)) }}</td></tr>
    </table>
    <div class="note">{{ $branding['footer_note'] ?? '' }}</div>
    <table class="footer-meta">
        <tr>
            <td class="label">هاتف الشركة:</td>
            <td class="value num">{{ $branding['company_mobile'] ?? '' }}</td>
            <td class="label">عنوان الشركة:</td>
            <td class="value">{{ $branding['company_address'] ?? '' }}</td>
        </tr>
    </table>
</body>
</html>

