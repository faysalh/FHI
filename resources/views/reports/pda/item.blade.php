@extends('reports.layouts.app')
@section('title', $item['name'])

@section('content')
    <header class="page-header">
        <h1>{{ $item['name'] }}</h1>
        <p class="hint">@if ($item['code'] !== ''){{ $item['code'] }} · @endif Stock {{ display_number($item['balance'], 2) }}</p>
    </header>
    @include('reports.pda.partials.subnav')
    <h2>Units and prices</h2>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Unit</th>
                    <th>Scale</th>
                    <th>1</th>
                    <th>2</th>
                    <th>3</th>
                    <th>4</th>
                    <th>5</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($item['units'] as $unit)
                    <tr>
                        <td>{{ $unit['name'] }}</td>
                        <td>{{ display_number($unit['scale'], 2) }}</td>
                        @foreach ($unit['prices'] as $price)
                            <td>{{ display_number($price, 2) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <h2>Balance in every store</h2>
    <div class="table-scroll">
        <table>
            <thead>
                <tr><th>Store</th><th>Balance</th><th>Unit</th></tr>
            </thead>
            <tbody>
                @forelse ($balances as $row)
                    <tr>
                        <td>{{ $row->fld_store_name ?? '' }}</td>
                        <td>{{ display_number($row->Balance ?? $row->balance ?? 0, 2) }}</td>
                        <td>{{ $row->Unit ?? $row->unit ?? '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">No store balances were returned.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
