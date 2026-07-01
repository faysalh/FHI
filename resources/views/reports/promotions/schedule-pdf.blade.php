@php use App\Support\ArabicPdfText as Ar; @endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Promotions schedule</title>
    <style>
        @include('reports.partials.pdf-styles')
        body { direction: ltr; unicode-bidi: normal; }
        table { direction: ltr; table-layout: fixed; width: 100%; }
        th, td { word-wrap: break-word; padding: 4px 6px; vertical-align: top; }
        th { font-weight: 700; }
        .sheet-block { page-break-after: always; }
        .sheet-block:last-child { page-break-after: auto; }
        .promoter-meta { margin: 0 0 10px; font-size: 12px; color: #334155; }
    </style>
</head>
<body>
@include('reports.partials.pdf-branding-header')

@foreach ($sheets as $sheet)
    @php
        $promoter = $sheet['promoter'] ?? null;
        $employeeName = trim((string) ($promoter->employee_name ?? 'Promoter'));
        $vehicle = trim((string) ($promoter->vehicle ?? ''));
        $meta = 'Week: '.($sheet['week_start'] ?? '').' — '.($sheet['week_end'] ?? '');
        if ($vehicle !== '') {
            $meta .= ' | Vehicle: '.$vehicle;
        }
    @endphp
    <div class="sheet-block">
        @include('reports.partials.pdf-title-block', [
            'title' => 'Promotions schedule — '.Ar::glyphs($employeeName),
            'meta' => $meta,
        ])

        @include('reports.promotions.partials.schedule-grid', [
            'sheet' => $sheet,
            'forPdf' => true,
        ])
    </div>
@endforeach

@include('reports.partials.pdf-footer')
</body>
</html>
