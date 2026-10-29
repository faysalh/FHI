@include('reports.pda.partials.styles')
@if (!empty($readError))
    <div class="alert alert--error" role="alert">{{ $readError }}</div>
@endif
<nav class="pda-subnav" aria-label="PDA sections">
    <a href="{{ route('reports.pda.index') }}" @class(['is-active' => request()->routeIs('reports.pda.index', 'reports.pda.customers.show', 'reports.pda.visits.show', 'reports.pda.vouchers.create')])>Customers</a>
    <a href="{{ route('reports.pda.items') }}" @class(['is-active' => request()->routeIs('reports.pda.items', 'reports.pda.items.show')])>Items</a>
    <a href="{{ route('reports.pda.targets') }}" @class(['is-active' => request()->routeIs('reports.pda.targets')])>Targets</a>
    @if (\App\Support\ReportAuthSession::canAccessReport('pda-locations'))
        <a href="{{ route('reports.pda.locations') }}" @class(['is-active' => request()->routeIs('reports.pda.locations')])>Locations</a>
    @endif
    <a href="{{ route('reports.pda.questionnaires') }}" @class(['is-active' => request()->routeIs('reports.pda.questionnaires', 'reports.pda.questionnaires.show')])>Questions</a>
    <a href="{{ route('reports.pda.unposted') }}" @class(['is-active' => request()->routeIs('reports.pda.unposted')])>Unposted</a>
</nav>
