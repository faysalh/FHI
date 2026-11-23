@php
    use App\Support\ReportGuide;
    use App\Support\ReportNavigation;

    $activeKey = ReportNavigation::activeKey();
    $pdaSalesStep = isset($salesman['user_id']) && request()->routeIs(
        'reports.pda.index',
        'reports.pda.customers.show',
        'reports.pda.visits.show',
        'reports.pda.vouchers.create',
    );
@endphp
@if ($pdaSalesStep)
    <div class="report-how-to-link-wrap">
        <form method="POST" action="{{ route('reports.pda.customers.refresh') }}" onsubmit="var button=this.querySelector('button'); if(button){ button.disabled=true; }">
            @csrf
            <button type="submit" class="report-how-to-link" title="Load customers added for this salesman">
                Refresh customers
            </button>
        </form>
    </div>
@elseif ($activeKey !== '' && $activeKey !== 'guide' && ReportGuide::hasTopic($activeKey))
    <p class="report-how-to-link-wrap">
        <a href="{{ ReportGuide::urlFor($activeKey) }}" class="report-how-to-link" title="Read how to use this page">
            How to use this page
        </a>
    </p>
@endif
