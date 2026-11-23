@php
    use App\Support\ArabicPdfText as Ar;
    use App\Services\ItWorkLogSqliteService;

    $title = 'IT Work Log';
    $statusLabel = match ($filters['status'] ?? 'all') {
        'in_progress' => 'In progress',
        'finished' => 'Finished',
        default => 'All',
    };
    $metaParts = ['Status: '.$statusLabel];
    if (($filters['date_from'] ?? '') !== '' || ($filters['date_to'] ?? '') !== '') {
        $metaParts[] = 'Started: '.(($filters['date_from'] ?? '') !== '' ? $filters['date_from'] : '…')
            .' — '.(($filters['date_to'] ?? '') !== '' ? $filters['date_to'] : '…');
    }
    if (($filters['q'] ?? '') !== '') {
        $metaParts[] = 'Search: '.$filters['q'];
    }
    $meta = implode(' | ', $metaParts);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
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
        'title' => $title,
        'meta' => Ar::glyphs($meta),
        'centered' => true,
        'useGlyphs' => false,
    ])

    <table class="lines">
        <thead>
        <tr>
            <th class="center idx-col">#</th>
            <th>Title</th>
            <th>Notes</th>
            <th class="center">Started</th>
            <th class="center">Finished</th>
            <th class="center">Duration</th>
            <th class="center">Status</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $index => $row)
            @php
                $finishedAt = trim((string) ($row->finished_at ?? ''));
                $startedAt = (string) ($row->started_at ?? '');
                $duration = ItWorkLogSqliteService::formatDuration(
                    $startedAt,
                    $finishedAt !== '' ? $finishedAt : null
                );
            @endphp
            <tr>
                <td class="center idx-col">{{ $index + 1 }}</td>
                <td>{{ Ar::glyphs((string) ($row->title ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->notes ?? '')) }}</td>
                <td class="center">{{ $startedAt }}</td>
                <td class="center">{{ $finishedAt !== '' ? $finishedAt : '—' }}</td>
                <td class="center">{{ $duration }}</td>
                <td class="center">{{ $finishedAt !== '' ? 'Finished' : 'In progress' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="center">No entries.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
