@php
    use App\Support\ArabicPdfText as Ar;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Leave requests</title>
    <style>
        @include('reports.partials.pdf-styles')
        body { direction: ltr; unicode-bidi: normal; }
        table { direction: ltr; }
        td, th { word-wrap: break-word; }
    </style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', [
        'title' => 'Leave requests',
        'meta' => 'Status: '.$statusLabel,
        'centered' => true,
        'useGlyphs' => false,
    ])

    <table class="lines">
        <thead>
        <tr>
            <th>Employee</th>
            <th>Type</th>
            <th>When</th>
            <th>Length</th>
            <th>Status</th>
            <th>Requested by</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ Ar::glyphs((string) ($row->employee_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->type_label ?? '')) }}</td>
                <td>{{ $row->span_label }}</td>
                <td>{{ ($row->leave_type ?? '') === 'hourly' ? $row->duration_label : display_number($row->day_count) }}</td>
                <td>{{ Ar::glyphs((string) ($row->status_label ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->requested_by_name ?? '')) }}</td>
            </tr>
        @empty
            <tr><td colspan="6">No leave requests.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
