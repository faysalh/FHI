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
        <button
            type="button"
            class="report-nav-toggle btn-icon"
            aria-expanded="false"
            aria-controls="report-nav-menu"
            aria-label="Open report menu"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M4 7h16M4 12h16M4 17h16"/>
            </svg>
        </button>
        @if (ReportAuthSession::username())
            <span class="report-topbar__user muted">{{ ReportAuthSession::username() }}{{ ReportAuthSession::isSuperAdmin() ? ' · Admin' : '' }}</span>
        @endif
        <form method="POST" action="{{ route('logout') }}" class="report-topbar__logout">
            @csrf
            @include('reports.partials.icon-button', ['action' => 'logout', 'label' => 'Sign out'])
        </form>
    </div>
</header>
<nav id="report-nav-menu" class="report-nav" aria-label="Report sections">
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
        justify-content: flex-start;
        gap: 12px;
        flex-wrap: wrap;
    }
    .report-topbar__user {
        font-size: 12px;
        margin-left: auto;
        color: #cbd5e1;
    }
    .report-topbar__brand { text-decoration: none; color: inherit; flex: 0 0 auto; }
    .report-topbar__title { font-size: 17px; font-weight: 700; letter-spacing: -0.02em; }
    .report-topbar__logout { margin: 0; margin-left: auto; }
    .report-nav-toggle {
        display: none;
        color: #f8fafc;
        border-color: rgba(255, 255, 255, 0.35);
        background: rgba(255, 255, 255, 0.12);
    }
    .report-nav-toggle:hover {
        background: rgba(255, 255, 255, 0.22);
        border-color: rgba(255, 255, 255, 0.5);
    }
    .report-nav-toggle svg { width: 20px; height: 20px; }
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
    @media (max-width: 768px) {
        .report-topbar__inner {
            flex-wrap: nowrap;
        }
        .report-topbar__user {
            display: none;
        }
        .report-nav-toggle {
            display: inline-flex;
            order: 2;
            margin-left: auto;
        }
        .report-topbar__logout {
            order: 3;
            margin-left: 0;
        }
        .report-nav {
            display: none;
            flex-direction: column;
            gap: 14px;
            max-height: min(70vh, calc(100vh - 120px));
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            box-shadow: inset 0 1px 0 #f1f5f9;
        }
        .report-nav.is-open {
            display: flex;
        }
        .report-nav__links {
            flex-direction: column;
            align-items: stretch;
        }
        .report-nav__link {
            white-space: normal;
            padding: 10px 12px;
            font-size: 14px;
        }
    }
    @media (min-width: 769px) {
        .report-nav-toggle {
            display: none !important;
        }
    }
</style>
<script>
(function () {
    var toggle = document.querySelector('.report-nav-toggle');
    var nav = document.getElementById('report-nav-menu');
    if (!toggle || !nav) {
        return;
    }
    function setOpen(open) {
        nav.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close report menu' : 'Open report menu');
    }
    toggle.addEventListener('click', function () {
        setOpen(!nav.classList.contains('is-open'));
    });
    nav.querySelectorAll('.report-nav__link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.matchMedia('(max-width: 768px)').matches) {
                setOpen(false);
            }
        });
    });
    document.addEventListener('click', function (event) {
        if (!nav.classList.contains('is-open')) {
            return;
        }
        if (toggle.contains(event.target) || nav.contains(event.target)) {
            return;
        }
        setOpen(false);
    });
    window.addEventListener('resize', function () {
        if (window.matchMedia('(min-width: 769px)').matches) {
            setOpen(false);
        }
    });
})();
</script>
