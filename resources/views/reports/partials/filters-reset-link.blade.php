@php
    /** @var string $route */
    $params = $params ?? [];
@endphp
<a href="{{ route($route, $params) }}" class="filters-reset-link">Reset filters</a>
