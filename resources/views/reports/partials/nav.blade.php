@php
    use App\Support\ReportAuthSession;
    use App\Support\ReportNavigation;
    $activeKey = ReportNavigation::activeKey();
    $sections = ReportNavigation::sections();
    $homeRoute = ReportAuthSession::defaultLandingRouteName();
@endphp
<header class="report-topbar">
    <div class="report-topbar__inner">
        <a href="{{ route($homeRoute) }}" class="report-topbar__brand">
            <span class="report-topbar__title">Reporting</span>
        </a>
        @if (ReportAuthSession::username())
            <span class="report-topbar__user muted">{{ ReportAuthSession::username() }}{{ ReportAuthSession::isSuperAdmin() ? ' · Admin' : '' }}</span>
        @endif
        <form method="POST" action="{{ route('logout') }}" class="report-topbar__logout">
            @csrf
            @include('reports.partials.icon-button', ['action' => 'logout', 'label' => 'Sign out'])
        </form>
    </div>
</header>
<nav class="report-nav" aria-label="Report sections">
    @foreach ($sections as $section)
        <div class="report-nav__group">
            <span class="report-nav__label">{{ $section['label'] }}</span>
            <div class="report-nav__links">
                @foreach ($section['items'] as $item)
                    <a href="{{ route($item['route']) }}"
                       class="report-nav__link @if ($activeKey === $item['key']) is-active @endif"
                       @if(!empty($item['title'])) title="{{ $item['title'] }}" @endif
                       @if ($activeKey === $item['key']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </div>
        </div>
    @endforeach
</nav>
<style>
    .report-topbar {
        background: linear-gradient(90deg, #312e81 0%, #4f46e5 55%, #4338ca 100%);
        color: #f8fafc;
        border-bottom: 1px solid #3730a3;
    }
    .report-topbar__inner {
        max-width: 1440px;
        margin: 0 auto;
        padding: 10px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    .report-topbar__user {
        font-size: 12px;
        margin-left: auto;
        color: #cbd5e1;
    }
    .report-topbar__brand { text-decoration: none; color: inherit; }
    .report-topbar__title { font-size: 17px; font-weight: 700; letter-spacing: -0.02em; }
    .report-topbar__logout { margin: 0; }
    .btn--sm { padding: 5px 10px; font-size: 12px; }
    .report-nav {
        background: #fff;
        border-bottom: 1px solid #e2e8f0;
        padding: 10px 16px 12px;
        display: flex;
        flex-wrap: wrap;
        gap: 12px 20px;
        max-width: 100%;
    }
    .report-nav__group { min-width: 0; }
    .report-nav__label {
        display: block;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #94a3b8;
        margin-bottom: 6px;
    }
    .report-nav__links {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }
    .report-nav__link {
        padding: 5px 10px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        color: #475569;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .report-nav__link:hover {
        background: #f1f5f9;
        color: #0f172a;
    }
    .report-nav__link.is-active {
        background: #6366f1;
        color: #fff;
        border-color: #6366f1;
    }
    @media (max-width: 640px) {
        .report-nav__link { font-size: 12px; padding: 4px 8px; }
    }
</style>
