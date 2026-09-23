@php $activeComparisonTab = $activeComparisonTab ?? 'posted'; @endphp
<nav class="sub-tabs" aria-label="Comparison views">
    <a href="{{ route('reports.comparison.index', request()->query()) }}"
       class="{{ $activeComparisonTab === 'posted' ? 'active' : '' }}">Posted sales</a>
    <a href="{{ route('reports.comparison.asan.index', request()->query()) }}"
       class="{{ $activeComparisonTab === 'asan' ? 'active' : '' }}">Asan</a>
</nav>
