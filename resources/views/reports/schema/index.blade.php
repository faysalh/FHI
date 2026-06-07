@extends('reports.layouts.app')
@section('title', 'Sales & reporting schema')

@section('content')
<header class="page-header"><h1>Sales &amp; reporting schema</h1>
    </header>@php
        $activeView = $viewMode ?? 'browse';
        $queryBase = request()->query();
        $selectedConstraint = trim((string) ($filters['constraint'] ?? 'invoices_client_id_foreign'));
    @endphp
    <div class="subtabs">
        <a href="{{ route('reports.schema.index', array_merge($queryBase, ['view' => 'browse'])) }}" class="{{ $activeView === 'browse' ? 'active' : '' }}">Table browser</a>
        <a href="{{ route('reports.schema.index', array_merge($queryBase, ['view' => 'diagram'])) }}" class="{{ $activeView === 'diagram' ? 'active' : '' }}">Relations diagram</a>
        <a href="{{ route('reports.schema.index', array_merge($queryBase, ['view' => 'constraint-breakdown', 'constraint' => $selectedConstraint])) }}" class="{{ $activeView === 'constraint-breakdown' ? 'active' : '' }}">Constraint breakdown</a>
    </div>
    <p class="muted">Read-only preview of customer, account, document, and sales-related tables (with data). Search can find field names or values in the selected table; exact value matches are shown first.</p>

    @if ($activeView === 'browse' && !empty($commonColumnNames))
        <div class="panel panel-spaced">
            <h2>Column names common to all browsable tables</h2>
            <p class="muted">Exact name intersection across {{ count($tables) }} table(s). Useful for cross-table joins or consistent filters.</p>
            <p><code>{{ implode(', ', $commonColumnNames) }}</code></p>
        </div>
    @elseif ($activeView === 'browse' && count($tables) > 0)
        <div class="panel panel-spaced">
            <h2>Column names common to all browsable tables</h2>
            <p class="muted">No single column name appears in every table (intersection is empty). Tables use different field sets; compare per domain or join keys manually.</p>
        </div>
    @endif

    @if ($activeView === 'browse')
    <form method="GET" action="{{ route('reports.schema.index') }}" class="toolbar">
        <input type="hidden" name="view" value="browse">
        <label style="margin:0;">
            <span class="muted" style="display:block;margin-bottom:4px;font-weight:600;">Table</span>
            <select name="table" @if (count($tables) === 0) disabled @endif>
                @forelse ($tables as $table)
                    <option value="{{ $table['full_name'] }}" @selected(($selectedTable['full_name'] ?? null) === $table['full_name'])>
                        {{ $table['full_name'] }} ({{ $table['column_count'] }} columns, {{ $table['row_count'] }} rows)
                    </option>
                @empty
                    <option value="">No browsable table matches this search</option>
                @endforelse
            </select>
        </label>
        <label style="margin:0;">
            <span class="muted" style="display:block;margin-bottom:4px;font-weight:600;">Search fields or values</span>
            <input type="search" name="q" value="{{ $searchQueryInput ?? '' }}" placeholder="e.g. 50745, sales_man, fld_account_id_ref" autocomplete="off">
        </label>
        <label style="margin:0;">
            <span class="muted" style="display:block;margin-bottom:4px;font-weight:600;">Sample size</span>
            <select name="per_page">
                @foreach ([10, 20, 50] as $size)
                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 10) === $size)>{{ $size }} rows</option>
                @endforeach
            </select>
        </label>
        @include('reports.partials.icon-button', ['action' => 'load', 'label' => 'Load / search schema'])
    </form>

    @if (strlen(trim($searchQueryInput ?? '')) > 0 && !empty($searchHits))
        <div class="panel search-hits">
            <h2>Field search results</h2>
            <p class="muted">Query: <strong>{{ $searchQueryInput }}</strong> — only matching <code>dbo</code> columns/tables (up to 500). Browsable tables in the dropdown are restricted to these matches when the search returns results.</p>
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>Table</th>
                        <th>Column</th>
                        <th>Type</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($searchHits as $hit)
                        <tr>
                            <td><code>{{ $hit['full_name'] }}</code></td>
                            <td><code>{{ $hit['column'] }}</code></td>
                            <td>{{ $hit['data_type'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @elseif (strlen(trim($searchQueryInput ?? '')) > 0)
        <div class="panel search-hits muted">No column or table names matched your search (<strong>{{ $searchQueryInput }}</strong>) in <code>dbo</code>. Sample rows for the selected table are still filtered by value matches.</div>
    @endif

    @if (strlen(trim($searchQueryInput ?? '')) > 0 && !empty($searchHits) && count($tables) === 0)
        <div class="panel muted" style="margin-bottom:16px;">
            <strong>No browsable table</strong> matched this search (matches may only exist on other <code>dbo</code> tables in the list above). Clear the search box and submit to restore the full browsable table list.
        </div>
    @endif

    @if ($selectedTable)
        <div class="summary">
            <div class="panel">
                <h2>Selected Table</h2>
                <div><strong>Schema:</strong> {{ $selectedTable['schema'] }}</div>
                <div><strong>Table:</strong> {{ $selectedTable['table'] }}</div>
                <div><strong>Full name:</strong> {{ $selectedTable['full_name'] }}</div>
                <div><strong>Browsable tables (shown in list):</strong> {{ count($tables) }}</div>
                <div><strong>Column count:</strong> {{ count($columns) }}@if (strlen(trim($searchQueryInput ?? '')) > 0 && !empty($searchHits)) <span class="muted">(matching field search)</span>@endif</div>
                <div><strong>Sample rows:</strong> {{ $rows?->total() ?? 0 }}@if (strlen(trim($searchQueryInput ?? '')) > 0) <span class="muted">(matching value search)</span>@endif</div>
            </div>

            <div class="panel">
                <h2>Columns</h2>
                @if ($columns === [] && strlen(trim($searchQueryInput ?? '')) > 0)
                    <p class="muted">No column names contain all search terms. Clear the search or try different words.</p>
                @else
                <table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Nullable</th>
                        <th>Max Length</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($columns as $column)
                        <tr>
                            <td>{{ $column['name'] }}</td>
                            <td>{{ $column['data_type'] }}</td>
                            <td>{{ $column['is_nullable'] }}</td>
                            <td>{{ $column['max_length'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>

        <div class="panel">
            <h2>Sample Data @if (strlen(trim($searchQueryInput ?? '')) > 0)<span class="muted">(filtered)</span>@endif</h2>
            @php
                $items = $rows?->items() ?? [];
                $first = $items[0] ?? null;
            @endphp

            @if ($items === [])
                <div>No rows returned for this table.</div>
            @else
                <table>
                    <thead>
                    <tr>
                        @foreach (array_keys((array) $first) as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($items as $row)
                        <tr>
                            @foreach ((array) $row as $value)
                                <td>@if ($value !== null && ! is_bool($value) && is_numeric($value))
                                    {{ display_number($value) }}
                                @elseif (is_scalar($value) || $value === null)
                                    {{ (string) $value }}
                                @else
                                    {{ json_encode($value) }}
                                @endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <div style="margin-top: 12px;">
                    @if($rows) @include('reports.partials.pagination', ['paginator' => $rows]) @endif
                </div>
            @endif
        </div>
    @endif
    @elseif ($activeView === 'diagram')
        <div class="panel panel-spaced">
            <h2>How to read this diagram</h2>
            <p class="muted">Each line means <code>ParentTable.ParentColumn -&gt; ReferencedTable.ReferencedColumn</code>. This comes from foreign key metadata only and does not modify the database.</p>
        </div>

        <div class="panel panel-spaced">
            <h2>Relation map (text diagram)</h2>
            @if (!empty($relationDiagramLines))
                <div class="diagram-box">
@foreach ($relationDiagramLines as $line)
{{ $line }}
@endforeach
                </div>
            @else
                <p class="muted">No foreign key relations were found for the currently browsable tables.</p>
            @endif
        </div>

        <div class="panel">
            <h2>Relation details</h2>
            @if (!empty($relations))
                <table>
                    <thead>
                    <tr>
                        <th>Constraint</th>
                        <th>Parent</th>
                        <th>Referenced</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($relations as $relation)
                        <tr>
                            <td><code>{{ $relation['constraint_name'] }}</code></td>
                            <td><code>{{ $relation['schema'] }}.{{ $relation['parent_table'] }}.{{ $relation['parent_column'] }}</code></td>
                            <td><code>{{ $relation['schema'] }}.{{ $relation['referenced_table'] }}.{{ $relation['referenced_column'] }}</code></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted">No relation rows available.</p>
            @endif
        </div>
    @else
        @php
            $matchingRows = array_values(array_filter(
                $relations ?? [],
                static fn (array $relation): bool => strcasecmp($relation['constraint_name'] ?? '', $selectedConstraint) === 0
            ));
            $fallbackRows = array_values(array_filter(
                $relations ?? [],
                static fn (array $relation): bool => str_contains(
                    strtolower((string) ($relation['constraint_name'] ?? '')),
                    strtolower($selectedConstraint)
                )
            ));
        @endphp
        <div class="panel panel-spaced">
            <h2>Foreign key explanation</h2>
            <form method="GET" action="{{ route('reports.schema.index') }}" class="toolbar" style="grid-template-columns: 1fr auto;">
                <input type="hidden" name="view" value="constraint-breakdown">
                <label style="margin:0;">
                    <span class="muted" style="display:block;margin-bottom:4px;font-weight:600;">Constraint name</span>
                    <input type="search" name="constraint" value="{{ $selectedConstraint }}" placeholder="e.g. invoices_client_id_foreign" autocomplete="off">
                </label>
                @include('reports.partials.icon-button', ['action' => 'explain', 'label' => 'Explain constraint'])
            </form>
            <p class="muted">This tab explains one FK constraint at a time from the read-only relation metadata.</p>
        </div>

        @if (!empty($matchingRows))
            @php $first = $matchingRows[0]; @endphp
            <div class="panel panel-spaced">
                <h2>{{ $selectedConstraint }}</h2>
                <p><strong>What it means:</strong> every value in <code>{{ $first['schema'] }}.{{ $first['parent_table'] }}.{{ $first['parent_column'] }}</code> must exist in <code>{{ $first['schema'] }}.{{ $first['referenced_table'] }}.{{ $first['referenced_column'] }}</code>.</p>
                <p><strong>Why this exists:</strong> it enforces referential integrity so child records cannot point to non-existing parent records.</p>
                <p><strong>Join path:</strong> <code>{{ $first['schema'] }}.{{ $first['parent_table'] }}</code> joins to <code>{{ $first['schema'] }}.{{ $first['referenced_table'] }}</code> using those key columns.</p>
            </div>
            <div class="panel">
                <h2>Constraint column mapping</h2>
                <table>
                    <thead>
                    <tr>
                        <th>Constraint</th>
                        <th>Parent (child side)</th>
                        <th>Referenced (parent side)</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($matchingRows as $relation)
                        <tr>
                            <td><code>{{ $relation['constraint_name'] }}</code></td>
                            <td><code>{{ $relation['schema'] }}.{{ $relation['parent_table'] }}.{{ $relation['parent_column'] }}</code></td>
                            <td><code>{{ $relation['schema'] }}.{{ $relation['referenced_table'] }}.{{ $relation['referenced_column'] }}</code></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="panel">
                <h2>No exact constraint match</h2>
                <p class="muted">Could not find <code>{{ $selectedConstraint }}</code> in the currently loaded relation set.</p>
                @if (!empty($fallbackRows))
                    <p class="muted">Closest matches:</p>
                    <table>
                        <thead>
                        <tr>
                            <th>Constraint</th>
                            <th>Parent</th>
                            <th>Referenced</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($fallbackRows as $relation)
                            <tr>
                                <td><code>{{ $relation['constraint_name'] }}</code></td>
                                <td><code>{{ $relation['schema'] }}.{{ $relation['parent_table'] }}.{{ $relation['parent_column'] }}</code></td>
                                <td><code>{{ $relation['schema'] }}.{{ $relation['referenced_table'] }}.{{ $relation['referenced_column'] }}</code></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    @endif
@endsection
