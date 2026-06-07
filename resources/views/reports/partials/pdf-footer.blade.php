@php
    use App\Support\ArabicPdfText as Ar;

    $printedAt = $printedAt ?? now()->format('Y-m-d H:i');
@endphp
<div class="pdf-footer">
    {{ Ar::glyphsKeepLatinDigits('Generated '.$printedAt) }}
</div>
