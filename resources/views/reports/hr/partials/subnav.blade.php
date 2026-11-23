<style>
    .hr-subnav { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
    .hr-subnav a {
        display: inline-flex; align-items: center; justify-content: center;
        min-height: 44px; padding: 8px 14px; border-radius: 10px;
        background: #eef2ff; color: #312e81; text-decoration: none; font-weight: 600;
        border: 1px solid #c7d2fe;
    }
    .hr-subnav a.is-active { background: #4f46e5; color: #fff; border-color: #4338ca; }
</style>
<nav class="hr-subnav" aria-label="Employee records">
    @if (\App\Support\ReportAuthSession::canAccessReport('hr-employees'))
        <a href="{{ route('reports.hr.employees.index') }}" @class(['is-active' => request()->routeIs('reports.hr.employees.*')])>Employees</a>
    @endif
    @if (\App\Support\ReportAuthSession::canAccessReport('hr-leave') || \App\Support\ReportAuthSession::canAccessReport('hr-leave-approve'))
        <a href="{{ route('reports.hr.leave.index') }}" @class(['is-active' => request()->routeIs('reports.hr.leave.*')])>Leave</a>
    @endif
</nav>
