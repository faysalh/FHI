@extends('reports.layouts.app')
@section('title', 'Suspensions')

@section('content')
    <header class="page-header">
        <h1>Suspensions</h1>
    </header>

    <form id="client-suspensions-filter-form" method="GET" action="{{ route('reports.client-suspensions.index') }}">
        <div class="filters-grid filters-grid--compact">
            <div>
                <label for="q">Client</label>
                <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Name or code">
            </div>
        </div>
        <div class="btn-group" style="margin:12px 0;">
            <button class="btn" type="submit">Search</button>
            <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.client-suspensions.export.pdf') }}">Export PDF</a>
            <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.client-suspensions.export.csv') }}">Export CSV</a>
        </div>
    </form>

    @if ($errorMessage)
        <p class="hint" style="color:#b91c1c;">{{ $errorMessage }}</p>
    @endif

    @if ($search !== '')
        <h2>Search results</h2>
        <table>
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Code</th>
                    <th>Salesman</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($matches as $match)
                    @php
                        $accountId = strtoupper(trim((string) ($match->account_id ?? '')));
                        $already = $accountId !== '' && isset($suspendedIds[$accountId]);
                    @endphp
                    <tr>
                        <td>{{ $match->client_name ?? '' }}</td>
                        <td>{{ $match->client_code ?? '' }}</td>
                        <td>{{ $match->salesman_name ?? '' }}</td>
                        <td>
                            @if ($already)
                                Suspended
                            @else
                                <form method="POST" action="{{ route('reports.client-suspensions.store') }}">
                                    @csrf
                                    <input type="hidden" name="client_account_id" value="{{ $accountId }}">
                                    <input type="hidden" name="q" value="{{ $search }}">
                                    <button class="btn" type="submit">Suspend</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">No clients matched.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    <h2>Suspended clients</h2>
    <table>
        <thead>
            <tr>
                <th>Client</th>
                <th>Code</th>
                <th>Salesman</th>
                <th>Suspended by</th>
                <th>When</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($suspended as $row)
                <tr>
                    <td>{{ $row->client_name }}</td>
                    <td>{{ $row->client_code }}</td>
                    <td>{{ $row->salesman_name }}</td>
                    <td>{{ $row->suspended_by }}</td>
                    <td>{{ $row->suspended_at }}</td>
                    <td>
                        <form method="POST" action="{{ route('reports.client-suspensions.destroy', ['client' => $row->client_account_id, 'q' => $search]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn--secondary" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No suspended clients.</td></tr>
            @endforelse
        </tbody>
    </table>

    <script>
    (function () {
        document.querySelectorAll('.report-export-link').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var form = document.getElementById('client-suspensions-filter-form');
                if (!form) return;
                var params = new URLSearchParams(new FormData(form));
                window.location.href = link.getAttribute('data-export-base') + '?' + params.toString();
            });
        });
    })();
    </script>
@endsection
