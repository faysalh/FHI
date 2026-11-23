<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Leave request {{ $requestRow->id }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111; margin: 24px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; vertical-align: top; }
        th { width: 180px; background: #f8fafc; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 16px; }
        .toolbar a, .toolbar button { min-height: 40px; padding: 8px 14px; }
        @media print {
            .toolbar { display: none; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print</button>
        <a href="{{ route('reports.hr.leave.pdf', $requestRow->id) }}">Download PDF</a>
        <a href="{{ route('reports.hr.leave.index') }}">Back to leave</a>
    </div>
    @include('reports.hr.partials.leave-fields', ['requestRow' => $requestRow])
</body>
</html>
