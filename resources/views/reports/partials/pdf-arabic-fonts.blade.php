{{-- Embedded fonts for DomPDF reports with Arabic labels (DejaVu bold lacks Arabic glyphs → "?" boxes). --}}
@font-face {
    font-family: 'ReportNotoNaskhArabic';
    font-style: normal;
    font-weight: 400;
    src: url('{{ public_path('fonts/NotoNaskhArabic-Regular.ttf') }}') format('truetype');
}
@font-face {
    font-family: 'ReportNotoNaskhArabic';
    font-style: normal;
    font-weight: 700;
    src: url('{{ public_path('fonts/NotoNaskhArabic-Bold.ttf') }}') format('truetype');
}
