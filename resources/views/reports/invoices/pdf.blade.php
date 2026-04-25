<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    @php use App\Support\ArabicPdfText as Ar; @endphp
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111; direction: ltr; text-align: left; }
        .top { width: 100%; margin-bottom: 8px; table-layout: fixed; }
        .top td { vertical-align: top; }
        .title { font-size: 18px; font-weight: 700; text-align: right; }
        .company { font-size: 14px; font-weight: 700; text-align: center; }
        .small { font-size: 10px; color: #444; }
        .logo { width: 62px; height: 62px; object-fit: contain; }
        .meta-layout { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        .meta-layout > tbody > tr > td { width: 50%; vertical-align: top; }
        .meta-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .meta-table td { padding: 2px 4px; vertical-align: top; }
        .label { width: 38%; font-weight: 700; text-align: left; white-space: nowrap; }
        .value { width: 62%; text-align: left; unicode-bidi: plaintext; }
        .items { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed; }
        .items th, .items td { border: 1px solid #cfcfcf; padding: 5px; }
        .items th { background: #f3f4f6; }
        .items tfoot td { font-weight: 700; background: #fafafa; }
        .num { text-align: right; direction: ltr; unicode-bidi: embed; }
        .center { text-align: center; }
        .footer { margin-top: 8px; }
        .footer-panels { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .footer-panels > tbody > tr > td { width: 50%; vertical-align: top; }
        .total-box { width: 95%; border-collapse: collapse; }
        .total-box td { border: 1px solid #cfcfcf; padding: 5px; }
        .desc-box { width: 95%; border-collapse: collapse; }
        .desc-box td { border: 1px solid #cfcfcf; padding: 5px; }
        .desc-box .desc-content { height: 63px; vertical-align: top; }
        .note { margin-top: 6px; font-size: 10px; color: #333; text-align: right; unicode-bidi: plaintext; }
        .footer-meta { border-collapse: collapse; margin-top: 4px; width: 100%; table-layout: fixed; }
        .footer-meta td { padding: 2px 4px; font-size: 10px; vertical-align: top; }
        .arabic { direction: rtl; unicode-bidi: isolate; text-align: right; display: inline-block; max-width: 100%; }
    </style>
</head>
<body>
    <table class="top">
        <tr>
            <td class="small">
                @if (!empty($brandingLogoDataUri))
                    <img class="logo" src="{{ $brandingLogoDataUri }}" alt="logo">
                @endif
            </td>
            <td class="company"><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($branding['company_name'] ?? 'N.Z.Y. Company')) }}</span></td>
            <td class="small num">{{ now()->format('d.m.Y') }}</td>
        </tr>
    </table>

    <div class="title">Sales Invoice</div>

    <table class="meta-layout">
        <tr>
            <td>
                <table class="meta-table">
                    <tr><td class="label">Invoice no:</td><td class="value">{{ $invoice->invoice_no ?? $invoice->invoice_id ?? '' }}</td></tr>
                    <tr><td class="label">Printed at:</td><td class="value">{{ now()->format('Y/m/d') }}</td></tr>
                    <tr><td class="label">Created at (PDA):</td><td class="value">{{ $invoice->created_at ?? '' }}</td></tr>
                    <tr><td class="label">Salesman phone:</td><td class="value">{{ $invoice->salesman_phone ?? '' }}</td></tr>
                    <tr><td class="label">Client phone:</td><td class="value">{{ $invoice->client_phone ?? '' }}</td></tr>
                    <tr><td class="label">Store:</td><td class="value"><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($invoice->store_name ?? '')) }}</span></td></tr>
                </table>
            </td>
            <td>
                <table class="meta-table">
                    <tr><td class="label">Date:</td><td class="value">{{ $invoice->invoice_date ?? '' }}</td></tr>
                    <tr><td class="label">Salesman:</td><td class="value"><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($invoice->salesman_name ?? '')) }}</span></td></tr>
                    <tr><td class="label">Account:</td><td class="value"><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($invoice->client_name ?? '')) }}</span> ({{ $invoice->client_code ?? '' }})</td></tr>
                    <tr><td class="label">Address:</td><td class="value"><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($invoice->client_address ?? '')) }}</span></td></tr>
                    <tr><td class="label">City:</td><td class="value"><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($invoice->city_name ?? '')) }}</span></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <colgroup>
            <col style="width: 5%;">
            <col style="width: 23%;">
            <col style="width: 27%;">
            <col style="width: 10%;">
            <col style="width: 15%;">
            <col style="width: 20%;">
        </colgroup>
        <thead>
        <tr>
            <th class="center">#</th>
            <th>Item code</th>
            <th>Item name</th>
            <th class="num">Quantity</th>
            <th class="num">Unit price</th>
            <th class="num">Total</th>
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
                $unitPrice = $qty !== 0.0 ? $amount / $qty : 0.0;
                $sumQuantity += $qty;
                $sumAmount += $amount;
            @endphp
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td class="num">{{ $item->item_code ?? '' }}</td>
                <td><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($item->item_name ?? '')) }}</span></td>
                <td class="num">{{ display_number($qty) }}</td>
                <td class="num">{{ display_number($unitPrice) }} IQD</td>
                <td class="num">{{ display_number($amount) }} IQD</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            <td></td>
            <td></td>
            <td>Total</td>
            <td class="num">{{ display_number($sumQuantity) }}</td>
            <td></td>
            <td class="num">{{ display_number($sumAmount) }} IQD</td>
        </tr>
        </tfoot>
    </table>

    <div class="footer">
        <table class="footer-panels">
            <tr>
                <td>
                    <table class="total-box">
                        <tr><td class="label">Grand total</td><td class="num">{{ display_number((float) ($invoice->invoice_amount ?? 0)) }} IQD</td></tr>
                        <tr><td class="label">Due amount</td><td class="num">{{ display_number((float) ($invoice->client_due_amount ?? 0)) }} IQD</td></tr>
                        <tr><td class="label">Total quantity</td><td class="num">{{ display_number((float) ($invoice->quantity_total ?? 0)) }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="desc-box">
                        <tr><td class="label">Invoice description</td></tr>
                        <tr><td class="desc-content"><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($invoice->invoice_desc ?? '')) }}</span></td></tr>
                        <tr><td>&nbsp;</td></tr>
                    </table>
                </td>
            </tr>
        </table>
        <div class="note"><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($branding['footer_note'] ?? '')) }}</span></div>
        <table class="footer-meta">
            <tr>
                <td class="label">Company phone:</td>
                <td class="value">{{ $branding['company_mobile'] ?? '' }}</td>
                <td class="label">Company address:</td>
                <td class="value"><span class="arabic">{{ Ar::glyphsKeepLatinDigits((string) ($branding['company_address'] ?? '')) }}</span></td>
            </tr>
        </table>
    </div>
</body>
</html>

