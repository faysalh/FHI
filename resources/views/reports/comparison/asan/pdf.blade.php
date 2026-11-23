<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Comparison report — Asan</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
@php use App\Support\ArabicPdfText as Ar; @endphp
@include('reports.partials.pdf-branding-header')
@include('reports.partials.pdf-title-block', [
    'title' => 'Comparison report — Asan',
    'meta' => [
        'Source: tbl_multi_store_item_summary (S); amount = gross − line% − |qty|×|extra|',
        (string) ($monthSummary ?? ''),
        'From: '.($filters['date_from'] ?? '').' to '.($filters['date_to'] ?? ''),
        'City: '.(($filters['city'] ?? '') !== '' ? (string) $filters['city'] : 'All'),
        'Salesman: '.((string) ($salesmanLabel ?? 'All')),
        'Governorate: '.((string) ($governorateLabel ?? 'None')),
        'Exclude category: '.(($filters['exclude_category'] ?? '') !== '' ? (string) $filters['exclude_category'] : 'None'),
    ],
])

@include('reports.comparison.partials.month-results-table', ['pdf' => true])
@include('reports.partials.pdf-footer')
</body>
</html>
