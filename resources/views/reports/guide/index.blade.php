@extends('reports.layouts.app')
@section('title', 'How-to guide')

@section('content')
<header class="page-header">
    <h1>How-to guide</h1>
</header>
<p class="hint">What each report does and how to use it. Use <strong>Open …</strong> to go straight to that page, or jump from any report via <strong>How to use this page</strong>.</p>

@if ($sections === [])
    <p class="muted">No report topics are available for your account. Contact an administrator for access.</p>
@else
    <nav class="guide-toc card" aria-label="Guide contents">
        <h2 class="guide-toc__title">On this page</h2>
        @foreach ($sections as $section)
            <div class="guide-toc__group">
                <p class="guide-toc__section">{{ $section['label'] }}</p>
                <ul class="guide-toc__list">
                    @foreach ($section['topics'] as $topic)
                        <li>
                            <a href="#{{ \App\Support\ReportGuide::anchor($topic['key']) }}">{{ $topic['title'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    @foreach ($sections as $section)
        <section class="guide-section">
            <h2 class="guide-section__heading">{{ $section['label'] }}</h2>
            @foreach ($section['topics'] as $topic)
                <article id="{{ \App\Support\ReportGuide::anchor($topic['key']) }}" class="guide-topic card">
                    <div class="guide-topic__head">
                        <h3 class="guide-topic__title">{{ $topic['title'] }}</h3>
                        @if ($topic['can_open'])
                            <a href="{{ route($topic['route'], $topic['route_params'] ?? []) }}" class="btn btn--primary guide-topic__open">
                                {{ $topic['open_label'] }}
                            </a>
                        @else
                            <span class="muted guide-topic__locked" title="You do not have access to this report">No access</span>
                        @endif
                    </div>
                    <p class="guide-topic__summary">{{ $topic['summary'] }}</p>
                    <ul class="guide-topic__bullets">
                        @foreach ($topic['bullets'] as $bullet)
                            <li>{{ $bullet }}</li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </section>
    @endforeach
@endif
@endsection

@push('styles')
<style>
.guide-toc { margin-bottom: 20px; }
.guide-toc__title { margin: 0 0 10px; font-size: 15px; }
.guide-toc__group { margin-bottom: 10px; }
.guide-toc__section {
    margin: 0 0 4px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--rp-muted);
}
.guide-toc__list {
    margin: 0;
    padding-left: 18px;
    columns: 2;
    column-gap: 24px;
}
@media (max-width: 720px) {
    .guide-toc__list { columns: 1; }
}
.guide-section { margin-bottom: 28px; }
.guide-section__heading {
    font-size: 18px;
    margin: 0 0 12px;
    padding-bottom: 6px;
    border-bottom: 2px solid var(--rp-border);
}
.guide-topic { scroll-margin-top: 16px; }
.guide-topic__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}
.guide-topic__title { margin: 0; font-size: 16px; }
.guide-topic__open { white-space: nowrap; text-decoration: none; }
.guide-topic__summary { margin: 0 0 10px; color: #334155; line-height: 1.55; }
.guide-topic__bullets {
    margin: 0;
    padding-left: 20px;
    color: #475569;
    line-height: 1.5;
}
.guide-topic__bullets li { margin-bottom: 4px; }
</style>
@endpush
