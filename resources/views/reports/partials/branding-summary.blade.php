@php
    $branding = $reportBranding ?? [];
    $companyName = trim((string) ($branding['company_name'] ?? ''));
    $companyMobile = trim((string) ($branding['company_mobile'] ?? ''));
    $companyAddress = trim((string) ($branding['company_address'] ?? ''));
    $logoUrl = trim((string) ($branding['logo_url'] ?? ''));
@endphp

@if ($companyName !== '' || $companyMobile !== '' || $companyAddress !== '' || $logoUrl !== '')
    <div class="branding-bar">
        <div class="branding-bar__text">
            @if ($companyName !== '')
                <div class="branding-bar__name">{{ $companyName }}</div>
            @endif
            @if ($companyMobile !== '' || $companyAddress !== '')
                <div class="branding-bar__meta">
                    @if ($companyMobile !== '')
                        <span>{{ $companyMobile }}</span>
                    @endif
                    @if ($companyMobile !== '' && $companyAddress !== '')
                        <span aria-hidden="true"> · </span>
                    @endif
                    @if ($companyAddress !== '')
                        <span>{{ $companyAddress }}</span>
                    @endif
                </div>
            @endif
        </div>
        @if ($logoUrl !== '')
            <img src="{{ $logoUrl }}" alt="" class="branding-bar__logo">
        @endif
    </div>
@endif
