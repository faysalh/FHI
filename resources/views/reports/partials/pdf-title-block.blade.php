@php
    use App\Support\ArabicPdfText as Ar;

    $titleText = (string) ($title ?? '');
    $titleRendered = ($useGlyphs ?? true)
        ? (($glyphsKeepLatinDigits ?? false) ? Ar::glyphsKeepLatinDigits($titleText) : Ar::glyphs($titleText))
        : $titleText;
    $headClass = 'pdf-report-head'.(($centered ?? false) ? ' pdf-report-head--center' : '');
    $titleClass = 'pdf-title'.(($small ?? false) ? ' pdf-title--sm' : '');
@endphp
<div class="{{ $headClass }}">
    <h1 class="{{ $titleClass }}">{{ $titleRendered }}</h1>
    @if (! empty($meta))
        <div class="pdf-meta">
            @if (is_array($meta))
                @foreach ($meta as $line)
                    <div class="pdf-meta-row">{{ ($useGlyphs ?? true) ? Ar::glyphs((string) $line) : (string) $line }}</div>
                @endforeach
            @else
                {{ ($glyphsKeepLatinDigits ?? false) ? Ar::glyphsKeepLatinDigits((string) $meta) : (($useGlyphs ?? true) ? Ar::glyphs((string) $meta) : (string) $meta) }}
            @endif
        </div>
    @endif
</div>
