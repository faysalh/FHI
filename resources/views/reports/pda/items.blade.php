@extends('reports.layouts.app')
@section('title', 'PDA items')

@section('content')
    <header class="page-header">
        <h1>Items</h1>
        <p class="hint">Prices are the five tiers for {{ $salesman['name'] }}.</p>
    </header>
    @include('reports.pda.partials.subnav')
    <form method="GET" action="{{ route('reports.pda.items') }}" class="pda-search">
        <input type="search" name="q" value="{{ $search }}" placeholder="Name or code" aria-label="Search items">
        <button class="pda-btn" type="submit">Search</button>
    </form>
    @php $canOpenItem = \App\Support\ReportAuthSession::canAccessReport('pda-item-detail'); @endphp
    <div class="pda-catalog">
        @forelse ($items as $item)
            @if ($canOpenItem)
                <a class="pda-product" href="{{ route('reports.pda.items.show', ['item' => $item['id']]) }}">
            @else
                <div class="pda-product">
            @endif
                <span class="pda-product__photo">
                    @if (! empty($item['has_image']))
                        <img class="pda-product__img" src="{{ route('reports.pda.items.image', ['item' => $item['id']]) }}" alt="" loading="lazy" decoding="async" onerror="if(!this.dataset.retry){this.dataset.retry='1';this.src=this.src.split('?')[0]+'?retry=1';}else{this.outerHTML='<span class=&quot;pda-product__img pda-product__img--empty&quot;></span>';}">
                    @else
                        <span class="pda-product__img pda-product__img--empty" aria-hidden="true"></span>
                    @endif
                </span>
                <strong dir="auto">{{ $item['name'] }}</strong>
                @if (($item['units'][0]['prices'][5] ?? null) !== null)
                    <span class="pda-product__price">{{ display_number($item['units'][0]['prices'][5], 2) }}</span>
                @endif
                <span class="pda-product__meta">
                    @if ($item['code'] !== ''){{ $item['code'] }} · @endif
                    Stock {{ display_number($item['balance'], 2) }}
                </span>
            @if ($canOpenItem)
                </a>
            @else
                </div>
            @endif
        @empty
            <p class="muted">No items matched. Search by name or code.</p>
        @endforelse
    </div>
@endsection
