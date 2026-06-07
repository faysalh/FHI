@php
    use App\Support\ArabicPdfText as Ar;
    use App\Support\VisitsReportGrouping;
    use App\Support\VisitsReportRowValues;

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

    $summarizeRows = static function (iterable $rowSet) use ($monthVisit, $multiMonth, $monthSegments): array {
        $visitedCounts = [];
        $notVisitedCounts = [];
        $salesSums = [];
        if ($multiMonth ?? false) {
            foreach ($monthSegments ?? [] as $seg) {
                $alias = (string) ($seg['sql_alias'] ?? '');
                $visitedCounts[$alias] = 0;
                $notVisitedCounts[$alias] = 0;
                $salesSums[$alias] = 0.0;
            }
            foreach ($rowSet as $row) {
                foreach ($monthSegments ?? [] as $seg) {
                    $alias = (string) ($seg['sql_alias'] ?? '');
                    $hit = $monthVisit($row, $alias);
                    if ($hit) {
                        $visitedCounts[$alias]++;
                    } else {
                        $notVisitedCounts[$alias]++;
                    }
                    $salesSums[$alias] += VisitsReportRowValues::readSalesAmount($row, (string) ($seg['sales_sql_alias'] ?? ''));
                }
            }
        } else {
            $visitedCounts['single'] = 0;
            $notVisitedCounts['single'] = 0;
            $salesSums['single'] = 0.0;
            foreach ($rowSet as $row) {
                $hit = (int) ($row->visited ?? 0) === 1;
                if ($hit) {
                    $visitedCounts['single']++;
                } else {
                    $notVisitedCounts['single']++;
                }
                $salesSums['single'] += VisitsReportRowValues::readSalesAmount($row, 'month_sales');
            }
        }

        return [$visitedCounts, $notVisitedCounts, $salesSums];
    };
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Visits report</title>
    <style>
        @include('reports.partials.pdf-styles')
        table { table-layout: auto; }
        th, td { white-space: nowrap; padding: 3px 4px; }
        th { font-weight: 700; }
    </style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')

    @php
        $clientCount = is_countable($rows) ? count($rows) : 0;
        $meta = 'Period: '.$dateFrom.' - '.$dateTo;
        $meta .= ' | Clients: '.$clientCount;
        if ($cities !== []) {
            $meta .= ' | Cities: '.implode('، ', $cities);
        } else {
            $meta .= ' | Cities: all';
        }
        $meta .= ' | مندوب المبيعات: '.($salesmanName ? $salesmanName : 'الكل');
        if ($sortByCity ?? false) {
            $meta .= ' | Sort: city A-Z, not visited first per city';
        }
        if ($pdfTruncated ?? false) {
            $meta .= ' | PDF limited to first '.(int) ($pdfRowCap ?? 0).' clients — use CSV for full export';
        }

        [$grandVisited, $grandNotVisited, $grandSales] = $summarizeRows($rows);
    @endphp

    @include('reports.partials.pdf-title-block', ['title' => 'تقرير الزيارات', 'meta' => $meta])
    <table>
        <thead>
        @php
            $showMonthSales = (bool) ($showMonthSales ?? false);
            $visitColspan = ($multiMonth ?? false)
                ? count($monthSegments ?? []) * ($showMonthSales ? 2 : 1)
                : ($showMonthSales ? 2 : 1);
        @endphp
        @if (($multiMonth ?? false) && $showMonthSales)
        <tr>
            <th rowspan="2">#</th>
            <th rowspan="2">{{ Ar::glyphs('رمز العميل') }}</th>
            <th rowspan="2">{{ Ar::glyphs('اسم العميل') }}</th>
            <th rowspan="2">{{ Ar::glyphs('المدينة') }}</th>
            <th rowspan="2">{{ Ar::glyphs('العنوان') }}</th>
            @foreach ($monthSegments ?? [] as $seg)
                <th colspan="2" class="month-en">{{ $seg['label_en'] ?? $seg['label'] }}</th>
            @endforeach
        </tr>
        <tr>
            @foreach ($monthSegments ?? [] as $seg)
                <th class="month-en">Visit</th>
                <th class="month-en">Sales</th>
            @endforeach
        </tr>
        @else
        <tr>
            <th>#</th>
            <th>{{ Ar::glyphs('رمز العميل') }}</th>
            <th>{{ Ar::glyphs('اسم العميل') }}</th>
            <th>{{ Ar::glyphs('المدينة') }}</th>
            <th>{{ Ar::glyphs('العنوان') }}</th>
            @if ($multiMonth ?? false)
                @foreach ($monthSegments ?? [] as $seg)
                    <th class="month-en">{{ $seg['label_en'] ?? $seg['label'] }}</th>
                    @if ($showMonthSales)
                        <th class="month-en">Sales</th>
                    @endif
                @endforeach
            @else
                <th>{{ Ar::glyphs('الزيارة') }}</th>
                @if ($showMonthSales)
                    <th class="month-en">Sales</th>
                @endif
            @endif
        </tr>
        @endif
        </thead>
        <tbody>
        @php
            $rowNum = 0;
            $currentCity = null;
            $cityBuffer = [];
            $showMonthSalesPdf = (bool) ($showMonthSales ?? false);

            $renderCitySummary = static function (string $cityLabel, array $cityRows) use ($summarizeRows, $multiMonth, $monthSegments, $visitColspan, $showMonthSalesPdf): void {
                if ($cityRows === []) {
                    return;
                }
                [$visitedCounts, $notVisitedCounts, $salesSums] = $summarizeRows($cityRows);
                echo '<tr class="city-summary"><td colspan="5" class="summary-label">'.Ar::glyphs($cityLabel.' - تمت الزيارة').'</td>';
                if ($multiMonth ?? false) {
                    foreach ($monthSegments ?? [] as $seg) {
                        $alias = (string) ($seg['sql_alias'] ?? '');
                        echo '<td class="visit-yes">'.(string) ($visitedCounts[$alias] ?? 0).'</td>';
                        if ($showMonthSalesPdf) {
                            echo '<td class="num">'.display_number($salesSums[$alias] ?? 0).'</td>';
                        }
                    }
                } else {
                    echo '<td class="visit-yes">'.(string) ($visitedCounts['single'] ?? 0).'</td>';
                    if ($showMonthSalesPdf) {
                        echo '<td class="num">'.display_number($salesSums['single'] ?? 0).'</td>';
                    }
                }
                echo '</tr>';

                echo '<tr class="city-summary"><td colspan="5" class="summary-label">'.Ar::glyphs($cityLabel.' - لم تتم').'</td>';
                if ($multiMonth ?? false) {
                    foreach ($monthSegments ?? [] as $seg) {
                        $alias = (string) ($seg['sql_alias'] ?? '');
                        echo '<td class="visit-no">'.(string) ($notVisitedCounts[$alias] ?? 0).'</td>';
                        if ($showMonthSalesPdf) {
                            echo '<td class="num"></td>';
                        }
                    }
                } else {
                    echo '<td class="visit-no">'.(string) ($notVisitedCounts['single'] ?? 0).'</td>';
                    if ($showMonthSalesPdf) {
                        echo '<td class="num"></td>';
                    }
                }
                echo '</tr>';

                echo '<tr class="city-summary"><td colspan="5" class="summary-label">'.Ar::glyphs($cityLabel.' - عدد العملاء').'</td>';
                if ($multiMonth ?? false) {
                    echo '<td colspan="'.$visitColspan.'" style="text-align:center;">'.(string) count($cityRows).'</td>';
                } else {
                    echo '<td>'.(string) count($cityRows).'</td>';
                }
                echo '</tr>';
            };
        @endphp
        @foreach ($rows as $row)
            @php
                $city = VisitsReportGrouping::normalizeCity($row);
                if (($sortByCity ?? false) && $currentCity !== null && $city !== $currentCity) {
                    $renderCitySummary($currentCity, $cityBuffer);
                    $cityBuffer = [];
                }
                if (! ($sortByCity ?? false) || $currentCity !== $city) {
                    $currentCity = $city;
                }
                $cityBuffer[] = $row;
                $rowNum++;
            @endphp
            <tr>
                <td>{{ $rowNum }}</td>
                <td>{{ Ar::glyphs((string) ($row->client_code ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->city ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->client_address ?? '')) }}</td>
                @if ($multiMonth ?? false)
                    @foreach ($monthSegments ?? [] as $seg)
                        @php $hit = $monthVisit($row, $seg['sql_alias']); @endphp
                        <td class="{{ $hit ? 'visit-yes' : 'visit-no' }}">{{ $hit ? 'Visited' : 'Not visited' }}</td>
                        @if ($showMonthSales ?? false)
                            <td class="num">{{ display_number(VisitsReportRowValues::readSalesAmount($row, (string) $seg['sales_sql_alias'])) }}</td>
                        @endif
                    @endforeach
                @else
                    @php $visited = (int) ($row->visited ?? 0) === 1; @endphp
                    <td class="{{ $visited ? 'visit-yes' : 'visit-no' }}">{{ Ar::glyphs($visited ? 'تمت الزيارة' : 'لم تتم') }}</td>
                    @if ($showMonthSales ?? false)
                        <td class="num">{{ display_number(VisitsReportRowValues::readSalesAmount($row, 'month_sales')) }}</td>
                    @endif
                @endif
            </tr>
        @endforeach
        @if ($sortByCity ?? false)
            @php
                if ($currentCity !== null) {
                    $renderCitySummary($currentCity, $cityBuffer);
                }
            @endphp
        @endif
        </tbody>
        <tfoot>
        <tr>
            <td colspan="5" class="summary-label">{{ Ar::glyphs(($sortByCity ?? false) ? 'إجمالي عام - تمت الزيارة' : 'إجمالي تمت الزيارة') }}</td>
            @if ($multiMonth ?? false)
                @foreach ($monthSegments ?? [] as $seg)
                    @php $alias = (string) ($seg['sql_alias'] ?? ''); @endphp
                    <td class="visit-yes">{{ $grandVisited[$alias] ?? 0 }}</td>
                    @if ($showMonthSales ?? false)
                        <td class="num">{{ display_number($grandSales[$alias] ?? 0) }}</td>
                    @endif
                @endforeach
            @else
                <td class="visit-yes">{{ $grandVisited['single'] ?? 0 }}</td>
                @if ($showMonthSales ?? false)
                    <td class="num">{{ display_number($grandSales['single'] ?? 0) }}</td>
                @endif
            @endif
        </tr>
        <tr>
            <td colspan="5" class="summary-label">{{ Ar::glyphs(($sortByCity ?? false) ? 'إجمالي عام - لم تتم' : 'إجمالي لم تتم') }}</td>
            @if ($multiMonth ?? false)
                @foreach ($monthSegments ?? [] as $seg)
                    @php $alias = (string) ($seg['sql_alias'] ?? ''); @endphp
                    <td class="visit-no">{{ $grandNotVisited[$alias] ?? 0 }}</td>
                    @if ($showMonthSales ?? false)
                        <td class="num"></td>
                    @endif
                @endforeach
            @else
                <td class="visit-no">{{ $grandNotVisited['single'] ?? 0 }}</td>
                @if ($showMonthSales ?? false)
                    <td class="num"></td>
                @endif
            @endif
        </tr>
        <tr>
            <td colspan="5" class="summary-label">{{ Ar::glyphs(($sortByCity ?? false) ? 'إجمالي عام - عدد العملاء' : 'إجمالي عدد العملاء') }}</td>
            <td colspan="{{ $visitColspan }}" style="text-align: center;">{{ $clientCount }}</td>
        </tr>
        </tfoot>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
