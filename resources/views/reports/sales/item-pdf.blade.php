<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales report by item</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
@php use App\Support\ArabicPdfText as Ar; @endphp
@include('reports.partials.pdf-branding-header')
@include('reports.partials.pdf-title-block', [
    'title' => 'Sales report by item',
    'meta' => [
        'From: '.($filters['date_from'] ?? '').' to '.($filters['date_to'] ?? ''),
        'Salesman: '.Ar::glyphs((string) ($salesmanLabel ?? 'All')),
        'City: '.Ar::glyphs(($filters['city'] ?? '') !== '' ? (string) $filters['city'] : 'All'),
        'Category: '.Ar::glyphs(($filters['category'] ?? '') !== '' ? (string) $filters['category'] : 'All'),
        'Item: '.Ar::glyphs(($filters['q'] ?? '') !== '' ? (string) $filters['q'] : 'All'),
    ],
])

@include('reports.sales.partials.item-table', ['pdf' => true, 'rows' => $rows, 'showTotals' => $rows !== []])
@include('reports.partials.pdf-footer')
</body>
</html>
