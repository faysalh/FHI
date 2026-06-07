@extends('reports.layouts.app')
@section('title', 'Invoice branding')

@section('content')
<header class="page-header"><h1>Invoice branding</h1></header>
<p class="hint">These values are saved in SQLite-backed cache and used automatically in invoice Print/PDF.</p>

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
        @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save branding settings'])
    </form>
@endsection

@push('styles')
<style>
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
        input, textarea, button:not(.btn-icon) { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px; }
        textarea { min-height: 90px; resize: vertical; }
        .status { padding: 8px 10px; background: #e8f7ed; color: #166534; border-radius: 6px; margin-bottom: 10px; }
        .error-list { padding: 8px 10px; background: #fee2e2; color: #991b1b; border-radius: 6px; margin-bottom: 10px; }
        .help { color: #6b7280; font-size: 12px; margin-top: 6px; line-height: 1.45; }
        .logo-preview { margin-top: 8px; width: 120px; height: 120px; object-fit: contain; border: 1px solid #e5e7eb; padding: 6px; border-radius: 6px; background: #fff; }
</style>
@endpush
