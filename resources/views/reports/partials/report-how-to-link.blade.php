@php
    use App\Support\ReportGuide;
    use App\Support\ReportNavigation;

    $activeKey = ReportNavigation::activeKey();
@endphp
@if ($activeKey !== '' && $activeKey !== 'guide' && ReportGuide::hasTopic($activeKey))
    <p class="report-how-to-link-wrap">
        <a href="{{ ReportGuide::urlFor($activeKey) }}" class="report-how-to-link" title="Read how to use this page">
            How to use this page
        </a>
    </p>
@endif
