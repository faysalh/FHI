@php
    use App\Support\ArabicPdfText as Ar;

    $activeTab = $filters['tab'] ?? 'clients';
    $isGrowthTab = in_array($activeTab, ['growing', 'declining'], true);
    $limit = (int) ($filters['limit'] ?? 10);
    $tabTitle = (string) (($tabs ?? [])[$activeTab] ?? 'Rankings');
    $nameColumn = match ($activeTab) {
        'items' => 'Item',
        'salesmen' => 'Salesman',
        'categories' => 'Category',
        'cities' => 'City',
        default => 'Client',
    };
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $tabTitle }} rankings</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')

    <div class="pdf-report-head">
        <h1 class="pdf-title">{{ Ar::glyphs('Rankings — '.$tabTitle) }}</h1>
        <div class="pdf-meta">
            <div class="pdf-meta-row">{{ Ar::glyphsKeepLatinDigits('Period: '.($filters['date_from'] ?? '').' — '.($filters['date_to'] ?? '')) }}</div>
            @if ($isGrowthTab && !empty($priorPeriodLabel))
                <div class="pdf-meta-row">{{ Ar::glyphsKeepLatinDigits('Prior: '.$priorPeriodLabel) }}</div>
            @endif
            @if (! $isGrowthTab)
                <div class="pdf-meta-row">{{ Ar::glyphsKeepLatinDigits('Rank by: '.($filters['metric'] ?? 'amount')) }}</div>
            @endif
            <div class="pdf-meta-row">{{ Ar::glyphsKeepLatinDigits('Top '.$limit) }}</div>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>{{ Ar::glyphs($nameColumn) }}</th>
            @if ($activeTab === 'clients' || $isGrowthTab)
                <th>{{ Ar::glyphs('Code') }}</th>
            @endif
            @if ($activeTab === 'items')
                <th>{{ Ar::glyphs('Category') }}</th>
            @endif
            @if ($isGrowthTab)
                <th>{{ Ar::glyphs('Prior amount') }}</th>
                <th>{{ Ar::glyphs('Growth %') }}</th>
            @endif
            <th>{{ Ar::glyphs('Amount') }}</th>
            <th>{{ Ar::glyphs('Quantity') }}</th>
            <th>{{ Ar::glyphs('Weight') }}</th>
            <th>{{ Ar::glyphs('Invoices') }}</th>
            <th>{{ Ar::glyphs('Share %') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ Ar::glyphs((string) ($row->label ?? '')) }}</td>
                @if ($activeTab === 'clients' || $isGrowthTab)
                    <td>{{ Ar::glyphsKeepLatinDigits((string) ($row->client_code ?? '')) }}</td>
                @endif
                @if ($activeTab === 'items')
                    <td>{{ Ar::glyphs((string) ($row->secondary_label ?? '')) }}</td>
                @endif
                @if ($isGrowthTab)
                    <td class="num">{{ display_number((float) ($row->prior_amount ?? 0)) }}</td>
                    <td class="num">
                        @if (($row->growth_pct ?? null) !== null)
                            {{ display_number((float) $row->growth_pct, 1) }}%
                        @else
                            —
                        @endif
                    </td>
                @endif
                <td class="num">{{ display_number((float) ($row->amount ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->quantity ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->weight_total ?? 0), 1) }}</td>
                <td class="num">{{ display_number((float) ($row->invoice_count ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->share_pct ?? 0), 1) }}%</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @include('reports.partials.pdf-footer')
</body>
</html>
