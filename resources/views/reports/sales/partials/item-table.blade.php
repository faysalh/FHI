@php
    use App\Support\ArabicPdfText as Ar;
    $pdf = $pdf ?? false;
    $rows = $rows ?? [];
    $showTotals = $showTotals ?? ($rows !== []);
@endphp
<table>
    <thead>
        <tr>
            <th>Item code</th>
            <th>Item name</th>
            <th>Category</th>
            <th>Sales qty</th>
            <th>Free qty</th>
            <th>Discount</th>
            <th>Sales amount</th>
            <th>Return qty</th>
            <th>Return amount</th>
            <th>Net qty</th>
            <th>Net amount</th>
            <th>Avg price</th>
            <th>Weight (kg)</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            @php
                $netQty = (float) $row->sales_qty - (float) $row->return_qty;
                $netAmount = (float) $row->sales_amount - (float) $row->return_amount;
                $avg = $netQty != 0.0 ? $netAmount / $netQty : null;
                $code = (string) ($row->item_code ?? '');
                $name = (string) ($row->item_name ?? '');
                $category = (string) ($row->category_name ?? '');
            @endphp
            <tr>
                <td>{{ $pdf ? Ar::glyphs($code) : $code }}</td>
                <td>{{ $pdf ? Ar::glyphs($name) : $name }}</td>
                <td>{{ $pdf ? Ar::glyphs($category) : $category }}</td>
                <td>{{ display_number((float) $row->sales_qty) }}</td>
                <td>{{ display_number((float) $row->free_qty) }}</td>
                <td>{{ display_number((float) $row->sales_discount) }}</td>
                <td>{{ display_number((float) $row->sales_amount) }}</td>
                <td>{{ display_number((float) $row->return_qty) }}</td>
                <td>{{ display_number((float) $row->return_amount) }}</td>
                <td>{{ display_number($netQty) }}</td>
                <td>{{ display_number($netAmount) }}</td>
                <td>{{ $avg === null ? '' : display_number($avg) }}</td>
                <td>{{ display_number((float) $row->weight_total) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="13">{{ $pdf ? 'No items in this range.' : 'No items in this range.' }}</td>
            </tr>
        @endforelse
    </tbody>
    @if ($showTotals)
        @php
            $netQty = (float) $totals->sales_qty - (float) $totals->return_qty;
            $netAmount = (float) $totals->sales_amount - (float) $totals->return_amount;
        @endphp
        <tfoot>
            <tr>
                <th colspan="3">Total</th>
                <th>{{ display_number((float) $totals->sales_qty) }}</th>
                <th>{{ display_number((float) $totals->free_qty) }}</th>
                <th>{{ display_number((float) $totals->sales_discount) }}</th>
                <th>{{ display_number((float) $totals->sales_amount) }}</th>
                <th>{{ display_number((float) $totals->return_qty) }}</th>
                <th>{{ display_number((float) $totals->return_amount) }}</th>
                <th>{{ display_number($netQty) }}</th>
                <th>{{ display_number($netAmount) }}</th>
                <th></th>
                <th>{{ display_number((float) $totals->weight_total) }}</th>
            </tr>
        </tfoot>
    @endif
</table>
