@php
    use App\Support\ArabicPdfText as Ar;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Leave request {{ $requestRow->id }}</title>
    <style>
        @include('reports.partials.pdf-styles')
        body { direction: ltr; unicode-bidi: normal; }
        table { direction: ltr; width: 100%; }
        td, th { word-wrap: break-word; text-align: left; vertical-align: top; }
        th { width: 28%; }
    </style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', [
        'title' => 'Leave request',
        'meta' => $requestRow->status_label,
        'centered' => true,
        'useGlyphs' => false,
    ])

    <table class="lines">
        <tbody>
            <tr><th>Employee</th><td>{{ Ar::glyphs((string) ($requestRow->employee_name ?? '')) }}</td></tr>
            <tr><th>Job title</th><td>{{ Ar::glyphs((string) ($requestRow->job_title ?? '')) }}</td></tr>
            <tr><th>Phone</th><td>{{ Ar::glyphs((string) ($requestRow->employee_phone ?? '')) }}</td></tr>
            <tr><th>Emergency contact</th><td>{{ Ar::glyphs(trim((string) ($requestRow->emergency_name ?? '').(($requestRow->emergency_relationship ?? '') !== '' ? ' ('.$requestRow->emergency_relationship.')' : ''))) }}</td></tr>
            <tr><th>Emergency phone</th><td>{{ Ar::glyphs((string) ($requestRow->emergency_phone ?? '')) }}</td></tr>
            <tr><th>Type</th><td>{{ Ar::glyphs((string) ($requestRow->type_label ?? '')) }}</td></tr>
            @if (($requestRow->leave_type ?? '') === 'hourly')
                <tr><th>Date</th><td>{{ $requestRow->start_date }}</td></tr>
                <tr><th>From</th><td>{{ $requestRow->start_time }}</td></tr>
                <tr><th>To</th><td>{{ $requestRow->end_time }}</td></tr>
                <tr><th>Hours</th><td>{{ $requestRow->duration_label }}</td></tr>
            @else
                <tr><th>From</th><td>{{ $requestRow->start_date }}</td></tr>
                <tr><th>To</th><td>{{ $requestRow->end_date }}</td></tr>
                <tr><th>Days</th><td>{{ display_number($requestRow->day_count) }}</td></tr>
            @endif
            <tr><th>Reason</th><td>{{ Ar::glyphs((string) ($requestRow->reason ?? '')) }}</td></tr>
            <tr><th>Requested by</th><td>{{ Ar::glyphs((string) ($requestRow->requested_by_name ?? '')) }}</td></tr>
            <tr><th>Decision</th><td>{{ Ar::glyphs($requestRow->status === 'pending' ? 'Pending approval' : (string) $requestRow->status_label) }}</td></tr>
            <tr><th>Decided by</th><td>{{ Ar::glyphs((string) ($requestRow->decided_by_name ?? '')) }}</td></tr>
            <tr><th>Decided at</th><td>{{ $requestRow->decided_at }}</td></tr>
            <tr><th>Decision note</th><td>{{ Ar::glyphs((string) ($requestRow->decision_note ?? '')) }}</td></tr>
        </tbody>
    </table>
</body>
</html>
