@extends('reports.layouts.app')
@section('title', 'Identifier')

@section('content')
<header class="page-header"><h1>Identifier</h1></header>
<p class="hint">
        Use this page to record what important business terms mean in <strong>your</strong> system (tables, columns, relationships).
        The list below is the canonical reference; <strong>Jump to term</strong> scrolls to a row. Beside each explanation, the <strong>Sample data</strong> list shows five rows at once (column names are listed above each list).
    </p>

    <div class="picker">
        <label for="term-select">Jump to term</label>
        <select id="term-select" aria-label="Select a term">
            <option value="">— Choose a term —</option>
            @foreach ($terms as $t)
                <option value="{{ $t['key'] }}">{{ $t['label'] }}</option>
            @endforeach
        </select>
    </div>

    <table>
        <thead>
            <tr>
                <th class="term-col">Term</th>
                <th class="meaning-col">Meaning in this app</th>
                <th class="sample-col">Sample data (5 rows)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($terms as $t)
                <tr id="term-{{ $t['key'] }}">
                    <td>
                        <strong>{{ $t['label'] }}</strong>
                        <div class="term-key">{{ $t['key'] }}</div>
                    </td>
                    <td>{{ $t['description'] }}</td>
                    <td class="sample-wrap">
                        <div class="sample-cols">
                            @foreach ($t['sample_columns'] as $i => $col)
                                <span title="{{ $col }}"><strong>{{ $col }}</strong>@if (!$loop->last) · @endif</span>
                            @endforeach
                        </div>
                        <select
                            class="sample-listbox"
                            size="5"
                            aria-label="Sample data rows for {{ $t['label'] }}"
                        >
                            @foreach ($t['sample_rows'] as $row)
                                <option
                                    value="{{ $loop->index }}"
                                    title="{{ collect($t['sample_columns'])->map(fn ($c, $i) => $c . ': ' . ($row[$i] ?? ''))->implode(' | ') }}"
                                >
                                    @foreach ($row as $j => $cell)
                                        {{ $cell }}@if (!$loop->last) | @endif
                                    @endforeach
                                </option>
                            @endforeach
                        </select>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
<script>
(function () {
    var sel = document.getElementById('term-select');
    if (!sel) return;
    sel.addEventListener('change', function () {
        var key = sel.value;
        if (!key) return;
        var row = document.getElementById('term-' + key);
        if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + row.id);
            }
        }
    });
})();
</script>
@endsection

@push('styles')
<style>
.picker { margin-bottom: 20px; padding: 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; }
        .picker select { width: 100%; max-width: 420px; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 10px 12px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; }
        th.term-col { width: 18%; }
        th.meaning-col { width: 42%; }
        th.sample-col { width: 40%; }
        tr:target { background: #eff6ff; }
        .term-key { font-size: 12px; color: #64748b; font-family: ui-monospace, monospace; }
        .sample-wrap { min-width: 260px; max-width: 420px; }
        .sample-cols {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 6px;
            line-height: 1.35;
        }
        select.sample-listbox {
            width: 100%;
            padding: 6px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            font-family: ui-monospace, monospace;
            background: #fafafa;
            line-height: 1.35;
        }
        select.sample-listbox option { padding: 4px 6px; }
</style>
@endpush
