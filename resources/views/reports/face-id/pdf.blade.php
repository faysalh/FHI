@php
    use App\Support\ArabicPdfText as Ar;

    $dateFrom = $filters['date_from'] ?? '';
    $dateTo = $filters['date_to'] ?? '';
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Face ID attendance</title>
    <style>
        @include('reports.partials.pdf-styles')
        body { direction: ltr; unicode-bidi: normal; font-size: 11px; }
        table { direction: ltr; width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; word-wrap: break-word; }
        th { background: #f1f5f9; text-align: left; }
        .num { text-align: right; }
    </style>
</head>
<body>
@include('reports.partials.pdf-branding-header', ['branding' => $branding ?? null])

@include('reports.partials.pdf-title-block', [
    'title' => 'Face ID attendance',
    'meta' => ['Period: '.$dateFrom.' — '.$dateTo],
    'useGlyphs' => false,
])

<table>
    <thead>
    <tr>
        <th>Employee</th>
        <th>Code</th>
        <th>Event</th>
        <th>Recorded at</th>
        <th class="num">Confidence</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>
            <td>{{ Ar::glyphs((string) ($row->employee_name ?? '')) }}</td>
            <td>{{ Ar::glyphs((string) ($row->employee_code ?? '')) }}</td>
            <td>{{ ($row->event_type ?? '') === 'clock_in' ? 'Clock in' : 'Clock out' }}</td>
            <td>{{ $row->recorded_at }}</td>
            <td class="num">{{ $row->confidence !== null ? number_format((float) $row->confidence, 2) : '' }}</td>
        </tr>
    @empty
        <tr><td colspan="5">No records for this period.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
