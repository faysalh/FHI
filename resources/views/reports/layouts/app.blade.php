<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Reports') — Reporting</title>
    @include('reports.partials.theme')
    @include('reports.partials.lab-design-system')
    @include('reports.partials.compact-filters-styles')
    @stack('head')
    @stack('styles')
</head>
<body class="report-app">
    @include('reports.partials.nav')
    <main class="report-main">
        <div class="report-container @yield('container-class')">
            @include('reports.partials.flash-messages')
            @include('reports.partials.report-how-to-link')
            @yield('content')
        </div>
    </main>
    @include('reports.partials.page-header-how-to-script')
    @if ($canReceiveTaskNotifications ?? false)
        @include('reports.partials.tasks-browser-notifications')
    @endif
    @stack('scripts')
</body>
</html>
