<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice branding</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 16px; background: #f5f5f5; }
        .container { max-width: 960px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 16px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
        .tabs a { padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: #333; background: #eee; font-size: 14px; }
        .tabs a.active { background: #2563eb; color: #fff; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
        label { display: block; font-size: 13px; color: #4b5563; margin-bottom: 4px; }
        input, textarea, button { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px; }
        textarea { min-height: 90px; resize: vertical; }
        button { width: auto; background: #2563eb; color: #fff; border: 0; cursor: pointer; margin-top: 10px; }
        .status { padding: 8px 10px; background: #e8f7ed; color: #166534; border-radius: 6px; margin-bottom: 10px; }
        .hint { color: #6b7280; font-size: 13px; margin-bottom: 12px; }
        .error-list { padding: 8px 10px; background: #fee2e2; color: #991b1b; border-radius: 6px; margin-bottom: 10px; }
        .help { color: #6b7280; font-size: 12px; margin-top: 6px; line-height: 1.45; }
        .logo-preview { margin-top: 8px; width: 120px; height: 120px; object-fit: contain; border: 1px solid #e5e7eb; padding: 6px; border-radius: 6px; background: #fff; }
    </style>
</head>
<body>
<div class="container">
    <nav class="tabs">
        <a href="{{ route('reports.sales.index') }}">Sales report</a>
        <a href="{{ route('reports.sales-item-average.index') }}">Sales by item average</a>
        <a href="{{ route('reports.deliveries.index') }}">Deliveries</a>
        <a href="{{ route('reports.invoices.index') }}">Invoices</a>
        <a href="{{ route('reports.invoice-branding.index') }}" class="active">Invoice branding</a>
        <a href="{{ route('reports.cities.index') }}">Cities</a>
        <a href="{{ route('reports.visits.index') }}">Visits</a>
        <a href="{{ route('reports.schema.index') }}">Schema</a>
        <a href="{{ route('reports.customers.index') }}">Sample accounts</a>
        <a href="{{ route('reports.identifier.index') }}">Identifier</a>
    </nav>

    <h1>Invoice branding</h1>
    <p class="hint">These values are saved in SQLite-backed cache and used automatically in invoice Print/PDF.</p>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="error-list">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('reports.invoice-branding.save') }}" enctype="multipart/form-data">
        @csrf
        <div class="grid">
            <div>
                <label for="company_name">Company name</label>
                <input id="company_name" name="company_name" type="text" value="{{ old('company_name', $settings['company_name'] ?? '') }}" required>
            </div>
            <div>
                <label for="company_mobile">Company mobile (footer)</label>
                <input id="company_mobile" name="company_mobile" type="text" value="{{ old('company_mobile', $settings['company_mobile'] ?? '') }}">
            </div>
            <div>
                <label for="invoice_direction">Invoice direction</label>
                <select id="invoice_direction" name="invoice_direction">
                    <option value="rtl" @selected(old('invoice_direction', $settings['invoice_direction'] ?? 'rtl') === 'rtl')>RTL (Arabic style)</option>
                    <option value="ltr" @selected(old('invoice_direction', $settings['invoice_direction'] ?? 'rtl') === 'ltr')>LTR (English style)</option>
                </select>
            </div>
            <div style="grid-column: 1 / -1;">
                <label for="company_address">Company address (footer)</label>
                <input id="company_address" name="company_address" type="text" value="{{ old('company_address', $settings['company_address'] ?? '') }}">
            </div>
            <div style="grid-column: 1 / -1;">
                <label for="footer_note">Footer note</label>
                <textarea id="footer_note" name="footer_note">{{ old('footer_note', $settings['footer_note'] ?? '') }}</textarea>
            </div>
            <div>
                <label for="logo">Company logo</label>
                <input id="logo" name="logo" type="file" accept="image/*">
                <div class="help">
                    Recommended logo: 800x800 px (1:1), PNG/WebP, transparent background, max 2 MB.
                    Minimum accepted: 300x300 px, square ratio.
                </div>
                @if (!empty($settings['logo_path']))
                    <img src="{{ asset('storage/'.$settings['logo_path']) }}" class="logo-preview" alt="Current logo">
                    <label style="margin-top: 8px;"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                @endif
            </div>
        </div>
        <button type="submit">Save settings</button>
    </form>
</div>
</body>
</html>

