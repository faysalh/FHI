@php
    use App\Support\ArabicPdfText as Ar;

    $monthVisit = static function (object $row, string $alias): bool {
        if (isset($row->{$alias})) {
            return (int) $row->{$alias} === 1;
        }
        $low = strtolower($alias);
        if (isset($row->{$low})) {
            return (int) $row->{$low} === 1;
        }

        return false;
    };
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Visits report</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; margin: 12px; direction: ltr; unicode-bidi: normal; }
        h1 { font-size: 14px; margin: 0 0 8px 0; }
        .meta { font-size: 9px; color: #444; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; direction: ltr; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 3px 4px; text-align: left; word-wrap: break-word; }
        th { background: #f0f0f0; font-size: 9px; }
        th.month-en { text-align: left; }
        /* Match on-screen emphasis: visited = green, no visit = yellow (PDF export) */
        td.visit-yes { background: #dcfce7; color: #166534; font-weight: 600; }
        td.visit-no { background: #fef9c3; color: #854d0e; font-weight: 600; }
    </style>
</head>
<body>
    @php
        $meta = 'Period: '.$dateFrom.' — '.$dateTo;
        if ($cities !== []) {
            $meta .= ' | Cities: '.implode('، ', $cities);
        } else {
            $meta .= ' | Cities: all';
        }
        if ($salesmanId) {
            $meta .= ' | Salesman account id: '.$salesmanId;
        } else {
            $meta .= ' | Salesman: all';
        }
    @endphp

    <h1>{{ Ar::glyphs('تقرير الزيارات') }}</h1>
    <div class="meta">{{ Ar::glyphs($meta) }}</div>
    <table>
        <thead>
        <tr>
            <th>{{ Ar::glyphs('رمز العميل') }}</th>
            <th>{{ Ar::glyphs('اسم العميل') }}</th>
            <th>{{ Ar::glyphs('المدينة') }}</th>
            <th>{{ Ar::glyphs('مندوب المبيعات') }}</th>
            @if ($multiMonth ?? false)
                @foreach ($monthSegments ?? [] as $seg)
                    {{-- English month columns: DomPDF/DejaVu handle Latin reliably; avoids Arabic glyph issues --}}
                    <th class="month-en">{{ $seg['label_en'] ?? $seg['label'] }}</th>
                @endforeach
            @else
                <th>{{ Ar::glyphs('الزيارة') }}</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ Ar::glyphs((string) ($row->client_code ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->city ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->salesman_name ?? '')) }}</td>
                @if ($multiMonth ?? false)
                    @foreach ($monthSegments ?? [] as $seg)
                        @php $hit = $monthVisit($row, $seg['sql_alias']); @endphp
                        <td class="{{ $hit ? 'visit-yes' : 'visit-no' }}">{{ $hit ? 'Visited' : 'Not visited' }}</td>
                    @endforeach
                @else
                    @php $visited = (int) ($row->visited ?? 0) === 1; @endphp
                    <td class="{{ $visited ? 'visit-yes' : 'visit-no' }}">{{ Ar::glyphs($visited ? 'تمت الزيارة' : 'لم تتم') }}</td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
