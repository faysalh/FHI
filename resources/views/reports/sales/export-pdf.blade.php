@php
    use App\Support\ArabicPdfText as Ar;

    $pdfMeta = 'Period: '.$dateFrom.' — '.$dateTo;
    $pdfMeta .= ' | Mode: '.$modeLabel;
    if (! empty($q)) {
        $pdfMeta .= ' | Category filter: '.$q;
    }
    if (! empty($customerLabel)) {
        $pdfMeta .= ' | Customers: '.$customerLabel;
    } else {
        $pdfMeta .= ' | Customers: all';
    }
    if (! empty($governorateLabel ?? '')) {
        $pdfMeta .= ' | Governorate: '.$governorateLabel;
    }
    if (! empty($salesmanLabel ?? '')) {
        $pdfMeta .= ' | Salesmen: '.$salesmanLabel;
    }
    if (! empty($storageLabel ?? '')) {
        $pdfMeta .= ' | Storage: '.$storageLabel;
    }

    $showQuantity = $includeQuantity ?? true;
    $showAmount = $includeAmount ?? true;
    $showWeight = $includeWeight ?? true;
    $metricFlags = [
        'showQuantity' => $showQuantity,
        'showAmount' => $showAmount,
        'showWeight' => $showWeight,
    ];
@endphp
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Sales report</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
    @include('reports.partials.pdf-branding-header')
    @include('reports.partials.pdf-title-block', ['title' => 'Sales report', 'meta' => $pdfMeta])

    @if ($mode === 'totals' && ! empty($grandTotals))
        <table>
            <thead>
            <tr>
                @if ($showQuantity)
                    <th>{{ Ar::glyphs('Quantity (pcs)') }}</th>
                @endif
                @if ($showAmount)
                    <th>{{ Ar::glyphs('Amount (IQD)') }}</th>
                @endif
                @if ($showWeight)
                    <th>{{ Ar::glyphs('Weight (kg)') }}</th>
                @endif
            </tr>
            </thead>
            <tbody>
            <tr>
                @if ($showQuantity)
                    <td class="num">{{ display_number($grandTotals->units_sold ?? 0) }}</td>
                @endif
                @if ($showAmount)
                    <td class="num">{{ display_number($grandTotals->amount ?? 0) }}</td>
                @endif
                @if ($showWeight)
                    <td class="num">{{ display_number($grandTotals->weight_total ?? 0, 1) }}</td>
                @endif
            </tr>
            </tbody>
        </table>
        <p class="pdf-note">{{ Ar::glyphs('Total (all matching filters)') }}</p>
    @elseif ($mode === 'totals' && isset($rows[0]))
        @php $t = $rows[0]; @endphp
        <table>
            <thead>
            <tr>
                @if ($showQuantity)
                    <th>{{ Ar::glyphs('Quantity (pcs)') }}</th>
                @endif
                @if ($showAmount)
                    <th>{{ Ar::glyphs('Amount (IQD)') }}</th>
                @endif
                @if ($showWeight)
                    <th>{{ Ar::glyphs('Weight (kg)') }}</th>
                @endif
            </tr>
            </thead>
            <tbody>
            <tr>
                @if ($showQuantity)
                    <td class="num">{{ display_number($t->units_sold ?? 0) }}</td>
                @endif
                @if ($showAmount)
                    <td class="num">{{ display_number($t->amount ?? 0) }}</td>
                @endif
                @if ($showWeight)
                    <td class="num">{{ display_number($t->weight_total ?? 0, 1) }}</td>
                @endif
            </tr>
            </tbody>
        </table>
    @endif

    @if ($mode === 'by_client')
        <table>
            <thead>
            <tr>
                <th>{{ Ar::glyphs('Client code') }}</th>
                <th>{{ Ar::glyphs('Client name') }}</th>
                @if ($showQuantity)
                    <th class="num">{{ Ar::glyphs('Quantity (pcs)') }}</th>
                @endif
                @if ($showAmount)
                    <th class="num">{{ Ar::glyphs('Amount (IQD)') }}</th>
                @endif
                @if ($showWeight)
                    <th class="num">{{ Ar::glyphs('Weight (kg)') }}</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ Ar::glyphs((string) ($row->client_code ?? '')) }}</td>
                    <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
                    @if ($showQuantity)
                        <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    @endif
                    @if ($showAmount)
                        <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    @endif
                    @if ($showWeight)
                        <td class="num">{{ display_number($row->weight_total ?? 0, 1) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', array_merge([
                'grandTotals' => $grandTotals ?? null,
                'labelColspan' => 2,
                'trailingColspan' => 0,
            ], $metricFlags))
        </table>
    @endif

    @if ($mode === 'by_category')
        <table>
            <thead>
            <tr>
                <th>{{ Ar::glyphs('Category') }}</th>
                @if ($showQuantity)
                    <th class="num">{{ Ar::glyphs('Quantity (pcs)') }}</th>
                @endif
                @if ($showAmount)
                    <th class="num">{{ Ar::glyphs('Amount (IQD)') }}</th>
                @endif
                @if ($showWeight)
                    <th class="num">{{ Ar::glyphs('Weight (kg)') }}</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ Ar::glyphs((string) ($row->chicken_category ?? '')) }}</td>
                    @if ($showQuantity)
                        <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    @endif
                    @if ($showAmount)
                        <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    @endif
                    @if ($showWeight)
                        <td class="num">{{ display_number($row->weight_total ?? 0, 1) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', array_merge([
                'grandTotals' => $grandTotals ?? null,
                'labelColspan' => 1,
                'trailingColspan' => 0,
            ], $metricFlags))
        </table>
    @endif

    @if ($mode === 'by_category_items' && ! empty($categoryTotalsList ?? []))
        <h2 class="pdf-title pdf-title--sm">{{ Ar::glyphs('Totals by category') }}</h2>
        <table style="margin-bottom: 14px;">
            <thead>
            <tr>
                <th>{{ Ar::glyphs('Category') }}</th>
                @if ($showQuantity)
                    <th class="num">{{ Ar::glyphs('Quantity (pcs)') }}</th>
                @endif
                @if ($showAmount)
                    <th class="num">{{ Ar::glyphs('Amount (IQD)') }}</th>
                @endif
                @if ($showWeight)
                    <th class="num">{{ Ar::glyphs('Weight (kg)') }}</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($categoryTotalsList as $catRow)
                <tr>
                    <td>{{ Ar::glyphs((string) ($catRow['category'] ?? '')) }}</td>
                    @if ($showQuantity)
                        <td class="num">{{ display_number($catRow['units_sold'] ?? 0) }}</td>
                    @endif
                    @if ($showAmount)
                        <td class="num">{{ display_number($catRow['amount'] ?? 0) }}</td>
                    @endif
                    @if ($showWeight)
                        <td class="num">{{ display_number($catRow['weight_total'] ?? 0, 1) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', array_merge([
                'grandTotals' => $grandTotals ?? null,
                'labelColspan' => 1,
                'trailingColspan' => 0,
            ], $metricFlags))
        </table>
    @endif

    @if ($mode === 'by_category_items')
        <h2 class="pdf-title pdf-title--sm">{{ Ar::glyphs('Items by category') }}</h2>
        <table>
            <thead>
            <tr>
                <th>{{ Ar::glyphs('Category') }}</th>
                <th>{{ Ar::glyphs('Item name') }}</th>
                @if ($showQuantity)
                    <th class="num">{{ Ar::glyphs('Quantity (pcs)') }}</th>
                @endif
                @if ($showAmount)
                    <th class="num">{{ Ar::glyphs('Amount (IQD)') }}</th>
                @endif
                @if ($showWeight)
                    <th class="num">{{ Ar::glyphs('Weight (kg)') }}</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ Ar::glyphs((string) ($row->chicken_category ?? '')) }}</td>
                    <td>{{ Ar::glyphs((string) ($row->item_name ?? '')) }}</td>
                    @if ($showQuantity)
                        <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    @endif
                    @if ($showAmount)
                        <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    @endif
                    @if ($showWeight)
                        <td class="num">{{ display_number($row->weight_total ?? 0, 1) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', array_merge([
                'grandTotals' => $grandTotals ?? null,
                'labelColspan' => 2,
                'trailingColspan' => 0,
            ], $metricFlags))
        </table>
    @endif

    @if ($mode === 'by_category_by_client')
        <table>
            <thead>
            <tr>
                <th>{{ Ar::glyphs('Client code') }}</th>
                <th>{{ Ar::glyphs('Client name') }}</th>
                <th>{{ Ar::glyphs('Category') }}</th>
                @if ($showQuantity)
                    <th class="num">{{ Ar::glyphs('Quantity (pcs)') }}</th>
                @endif
                @if ($showAmount)
                    <th class="num">{{ Ar::glyphs('Amount (IQD)') }}</th>
                @endif
                @if ($showWeight)
                    <th class="num">{{ Ar::glyphs('Weight (kg)') }}</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ Ar::glyphs((string) ($row->client_code ?? '')) }}</td>
                    <td>{{ Ar::glyphs((string) ($row->client_name ?? '')) }}</td>
                    <td>{{ Ar::glyphs((string) ($row->chicken_category ?? '')) }}</td>
                    @if ($showQuantity)
                        <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    @endif
                    @if ($showAmount)
                        <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    @endif
                    @if ($showWeight)
                        <td class="num">{{ display_number($row->weight_total ?? 0, 1) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', array_merge([
                'grandTotals' => $grandTotals ?? null,
                'labelColspan' => 3,
                'trailingColspan' => 0,
            ], $metricFlags))
        </table>
    @endif

    @include('reports.partials.pdf-footer')
</body>
</html>
