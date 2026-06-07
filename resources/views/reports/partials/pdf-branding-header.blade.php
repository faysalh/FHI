@php
    use App\Support\ArabicPdfText as Ar;

    $brandingData = is_array($branding ?? null) ? $branding : [];
    $companyName = trim((string) ($brandingData['company_name'] ?? ''));
    $companyMobile = trim((string) ($brandingData['company_mobile'] ?? ''));
    $companyAddress = trim((string) ($brandingData['company_address'] ?? ''));
    $hasBranding = $companyName !== '' || $companyMobile !== '' || $companyAddress !== '' || ! empty($brandingLogoDataUri);
@endphp
@if ($hasBranding)
    <div class="pdf-branding-wrap">
        <table class="branding">
            <tr>
                <td class="company">
                    @if ($companyName !== '')
                        <strong>{{ Ar::glyphs((string) $companyName) }}</strong><br>
                    @endif
                    @if ($companyMobile !== '')
                        {{ Ar::glyphs((string) $companyMobile) }}<br>
                    @endif
                    @if ($companyAddress !== '')
                        {{ Ar::glyphs((string) $companyAddress) }}
                    @endif
                </td>
                <td class="logo-cell">
                    @if (! empty($brandingLogoDataUri))
                        <img src="{{ $brandingLogoDataUri }}" alt="logo">
                    @endif
                </td>
            </tr>
        </table>
    </div>
@endif
