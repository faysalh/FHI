<h1>Leave request</h1>
<p>{{ $requestRow->status_label }} · {{ $requestRow->duration_label }}</p>
<table>
    <tbody>
        <tr><th>Employee</th><td>{{ $requestRow->employee_name }}</td></tr>
        <tr><th>Job title</th><td>{{ $requestRow->job_title }}</td></tr>
        <tr><th>Phone</th><td>{{ $requestRow->employee_phone }}</td></tr>
        <tr><th>Emergency contact</th><td>{{ $requestRow->emergency_name }}@if ($requestRow->emergency_relationship) ({{ $requestRow->emergency_relationship }})@endif</td></tr>
        <tr><th>Emergency phone</th><td>{{ $requestRow->emergency_phone }}</td></tr>
        <tr><th>Type</th><td>{{ $requestRow->type_label }}</td></tr>
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
        <tr><th>Reason</th><td>{{ $requestRow->reason }}</td></tr>
        <tr><th>Requested by</th><td>{{ $requestRow->requested_by_name }}</td></tr>
        <tr><th>Decision</th><td>{{ $requestRow->status === 'pending' ? 'Pending approval' : $requestRow->status_label }}</td></tr>
        <tr><th>Decided by</th><td>{{ $requestRow->decided_by_name }}</td></tr>
        <tr><th>Decided at</th><td>{{ $requestRow->decided_at }}</td></tr>
        <tr><th>Decision note</th><td>{{ $requestRow->decision_note }}</td></tr>
    </tbody>
</table>
