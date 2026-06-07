<?php

declare(strict_types=1);

$base = dirname(__DIR__) . '/resources/views/reports/';

foreach (glob($base . '**/index.blade.php') ?: [] as $path) {
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $updated = preg_replace(
        "/@push\('head'\)\s*@include\('reports\.partials\.compact-filters-styles'\)\s*@endpush\s*/",
        '',
        $content
    );

    if ($updated !== null && $updated !== $content) {
        file_put_contents($path, $updated);
        echo 'cleaned ' . basename(dirname($path)) . '/' . basename($path) . "\n";
    }
}
