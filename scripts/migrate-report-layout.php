<?php

declare(strict_types=1);

$files = [
    'sales/index.blade.php',
    'comparison/index.blade.php',
    'sales-item-average/index.blade.php',
    'sales-by-salesman/index.blade.php',
    'storage-items/index.blade.php',
    'storage-items/evaluation.blade.php',
    'storage/index.blade.php',
    'deliveries/index.blade.php',
    'invoices/index.blade.php',
    'cities/index.blade.php',
    'visits/index.blade.php',
    'damages/index.blade.php',
    'customers/index.blade.php',
    'schema/index.blade.php',
    'identifier/index.blade.php',
    'invoice-branding/index.blade.php',
    'report-assembly/index.blade.php',
];

$base = dirname(__DIR__) . '/resources/views/reports/';

foreach ($files as $file) {
    $path = $base . $file;
    if (! is_file($path)) {
        echo "missing {$file}\n";
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        echo "read fail {$file}\n";
        continue;
    }

    if (! str_contains($content, '<!DOCTYPE html>')) {
        echo "skip {$file}\n";
        continue;
    }

    if (str_contains($content, "@extends('reports.layouts.app')")) {
        echo "done {$file}\n";
        continue;
    }

    if (! preg_match('/<title>(.*?)<\/title>/s', $content, $titleMatch)) {
        echo "no title {$file}\n";
        continue;
    }

    $title = html_entity_decode(trim($titleMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $styles = '';
    if (preg_match('/<style>(.*?)<\/style>/s', $content, $styleMatch)) {
        $styles = trim($styleMatch[1]);
        $patterns = [
            '/\s*body\s*\{[^}]*\}/s',
            '/\s*\.container\s*\{[^}]*\}/s',
            '/\s*\.tabs[^{]*\{[^}]*\}/s',
            '/\s*\.tabs a[^{]*\{[^}]*\}/s',
            '/\s*\.tabs a\.active\s*\{[^}]*\}/s',
            '/\s*input, select, button\s*\{[^}]*border:[^}]*\}/s',
            '/\s*label\s*\{[^}]*\}/s',
            '/\s*button\s*\{[^}]*background:[^}]*\}/s',
            '/\s*\.error\s*\{[^}]*\}/s',
            '/\s*\.hint\s*\{[^}]*\}/s',
            '/\s*\.muted\s*\{[^}]*\}/s',
        ];
        foreach ($patterns as $pattern) {
            $styles = preg_replace($pattern, '', $styles) ?? $styles;
        }
        $styles = trim($styles);
    }

    $headExtra = '';
    if (preg_match('/<\/style>\s*(.*?)(?=<\/head>)/s', $content, $headMatch)) {
        $headExtra = trim($headMatch[1]);
    }

    if (! preg_match("/@include\('reports\.partials\.branding-summary'\)\s*(.*)<\/body>\s*<\/html>/s", $content, $contentMatch)) {
        echo "no content {$file}\n";
        continue;
    }

    $body = trim($contentMatch[1]);
    $body = preg_replace('/<\/div>\s*$/', '', $body) ?? $body;
    $body = preg_replace('/@if\s*\(\s*\$errorMessage\s*\)[\s\S]*?@endif\s*/', '', $body, 1) ?? $body;
    $body = preg_replace("/@if\s*\(session\('error'\)\)[\s\S]*?@endif\s*/", '', $body, 1) ?? $body;
    $body = preg_replace('/@if\s*\(\s*\$errors->any\(\)\s*\)[\s\S]*?@endif\s*/', '', $body, 1) ?? $body;
    $body = preg_replace("/@if\s*\(session\('status'\)\)[\s\S]*?@endif\s*/", '', $body, 1) ?? $body;

    if (preg_match('/^<h1>/m', $body) && ! str_contains($body, 'page-header')) {
        $body = preg_replace(
            '/^(<h1>.*?<\/h1>\s*(?:<p class="hint">.*?<\/p>)?)/s',
            '<header class="page-header">$1</header>',
            $body,
            1
        ) ?? $body;
    }

    $body = preg_replace(
        '/<div style="margin-top: 12px;">\{\{ \$rows->links\(\) \}\}<\/div>/',
        '@include(\'reports.partials.pagination\', [\'paginator\' => $rows])',
        $body
    ) ?? $body;
    $body = preg_replace(
        '/<div style="margin-top: 12px;">\{\{ \$governorateRows->links\(\) \}\}<\/div>/',
        '@include(\'reports.partials.pagination\', [\'paginator\' => $governorateRows])',
        $body
    ) ?? $body;
    $body = preg_replace(
        '/\{\{\s*\$rows->links\(\)\s*\}\}/',
        '@include(\'reports.partials.pagination\', [\'paginator\' => $rows])',
        $body
    ) ?? $body;
    $body = preg_replace(
        '/\{\{\s*\$rows\?->links\(\)\s*\}\}/',
        '@if($rows) @include(\'reports.partials.pagination\', [\'paginator\' => $rows]) @endif',
        $body
    ) ?? $body;

    $containerClass = '';
    if (str_contains($file, 'storage/') || str_contains($file, 'cities/') || str_contains($file, 'storage-items')) {
        $containerClass = "\n@section('container-class', 'report-container--wide')";
    }

    $out = "@extends('reports.layouts.app')\n@section('title', '" . addslashes($title) . "'){$containerClass}\n\n@section('content')\n{$body}\n@endsection\n";

    if ($styles !== '') {
        $out .= "\n@push('styles')\n<style>\n{$styles}\n</style>\n@endpush\n";
    }

    if ($headExtra !== '') {
        $out .= "\n@push('head')\n{$headExtra}\n@endpush\n";
    }

    if (preg_match_all('/<script[\s\S]*?<\/script>/', $content, $scripts)) {
        $bodyScripts = [];
        foreach ($scripts[0] as $script) {
            if (str_contains($script, 'chart.js')) {
                continue;
            }
            if (str_contains($out, $script)) {
                continue;
            }
            $bodyScripts[] = $script;
        }
        if ($bodyScripts !== []) {
            $out .= "\n@push('scripts')\n" . implode("\n", $bodyScripts) . "\n@endpush\n";
        }
    }

    file_put_contents($path, $out);
    echo "migrated {$file}\n";
}
