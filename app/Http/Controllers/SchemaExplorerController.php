<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SchemaBrowserRequest;
use App\Services\SchemaExplorerService;
use Illuminate\Contracts\View\View;
use Throwable;

class SchemaExplorerController extends Controller
{
    public function __construct(
        private readonly SchemaExplorerService $service
    ) {}

    public function index(SchemaBrowserRequest $request): View
    {
        $filters = $request->validated();

        try {
            $browser = $this->service->browse($filters, $request);

            return view('reports.schema.index', [
                'tables' => $browser['tables'],
                'selectedTable' => $browser['selected_table'],
                'columns' => $browser['columns'],
                'rows' => $browser['rows'],
                'commonColumnNames' => $browser['common_column_names'],
                'searchQuery' => $browser['search_query'],
                'searchQueryInput' => $browser['search_query_input'],
                'searchHits' => $browser['search_hits'],
                'viewMode' => $browser['view'] ?? 'browse',
                'relations' => $browser['relations'] ?? [],
                'relationDiagramLines' => $browser['relation_diagram_lines'] ?? [],
                'filters' => $filters,
                'errorMessage' => null,
            ]);
        } catch (Throwable $exception) {
            return view('reports.schema.index', [
                'tables' => [],
                'selectedTable' => null,
                'columns' => [],
                'rows' => null,
                'commonColumnNames' => [],
                'searchQuery' => '',
                'searchQueryInput' => '',
                'searchHits' => [],
                'viewMode' => 'browse',
                'relations' => [],
                'relationDiagramLines' => [],
                'filters' => $filters,
                'errorMessage' => 'Unable to browse remote schema right now. Please retry.',
            ]);
        }
    }
}
