<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SchemaExplorerRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SchemaExplorerService
{
    public function __construct(
        private readonly SchemaExplorerRepository $repository
    ) {
    }

    /**
     * @param  array{table?: string|null, per_page?: int|null, q?: string|null}  $filters
     * @return array{
     *   tables: array<int, array{schema:string, table:string, full_name:string, column_count:int, row_count:int}>,
     *   selected_table: array{schema:string, table:string, full_name:string}|null,
     *   columns: array<int, array{name:string, data_type:string, is_nullable:string, max_length:int|null}>,
     *   rows: \Illuminate\Contracts\Pagination\LengthAwarePaginator|null,
     *   common_column_names: array<int, string>,
     *   search_query: string,
     *   search_query_input: string,
     *   search_hits: list<array{schema: string, table: string, full_name: string, column: string, data_type: string}>
     * }
     */
    public function browse(array $filters, Request $request): array
    {
        try {
            $allBrowsableTables = $this->repository->listTables();
            $searchQueryInput = isset($filters['q']) ? (string) $filters['q'] : '';
            $searchQuery = trim($searchQueryInput);
            $searchHits = [];

            $tables = $allBrowsableTables;
            if ($searchQuery !== '') {
                $searchHits = $this->repository->searchDboColumns($searchQuery);
                $matchedFullNames = [];
                foreach ($searchHits as $hit) {
                    $matchedFullNames[$hit['full_name']] = true;
                }
                $filteredBrowsable = array_values(array_filter(
                    $allBrowsableTables,
                    static fn (array $t): bool => isset($matchedFullNames[$t['full_name']])
                ));
                if ($searchHits !== []) {
                    $tables = $filteredBrowsable;
                }
            }

            $selected = $this->repository->resolveTableSelection($filters['table'] ?? null, $tables);
            $perPage = (int) ($filters['per_page'] ?? 10);

            $commonBaseTables = ($searchQuery !== '' && $searchHits !== []) ? $tables : $allBrowsableTables;
            $commonColumnNames = $this->repository->getCommonColumnNamesAcrossAllTables($commonBaseTables);

            $searchHitsDisplay = $searchHits;
            if ($searchQuery !== '' && $searchHits !== [] && $tables !== []) {
                $allow = array_flip(array_map(static fn (array $t): string => $t['full_name'], $tables));
                $searchHitsDisplay = array_values(array_filter(
                    $searchHits,
                    static fn (array $h): bool => isset($allow[$h['full_name']])
                ));
            }

            $columns = [];
            $rows = null;
            if ($selected !== null) {
                $columns = $this->repository->getColumns($selected['schema'], $selected['table']);
                if ($searchQuery !== '') {
                    $columns = $this->filterColumnsBySearchQuery($columns, $searchQuery);
                }
                $rows = $this->repository->getSampleRows(
                    $selected['schema'],
                    $selected['table'],
                    $perPage,
                    $searchQuery !== '' ? $searchQuery : null
                );
            }

            return [
                'tables' => $tables,
                'selected_table' => $selected,
                'columns' => $columns,
                'rows' => $rows,
                'common_column_names' => $commonColumnNames,
                'search_query' => $searchQuery,
                'search_query_input' => $searchQueryInput,
                'search_hits' => $searchHitsDisplay,
            ];
        } catch (Throwable $exception) {
            Log::error('Schema browser failed.', [
                'request_id' => (string) $request->header('X-Request-Id', ''),
                'db_host' => (string) config('database.connections.'.config('database.default').'.host', ''),
                'db_connection' => (string) config('database.default'),
                'filters' => $filters,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * When searching, only columns whose names contain every token (substring, case-insensitive).
     *
     * @param  array<int, array{name:string, data_type:string, is_nullable:string, max_length:int|null}>  $columns
     * @return array<int, array{name:string, data_type:string, is_nullable:string, max_length:int|null}>
     */
    private function filterColumnsBySearchQuery(array $columns, string $searchQuery): array
    {
        /** @var list<string> $tokens */
        $tokens = preg_split('/\s+/u', trim($searchQuery), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return $columns;
        }

        return array_values(array_filter($columns, function (array $col) use ($tokens): bool {
            $name = strtolower($col['name']);
            foreach ($tokens as $tok) {
                if (! str_contains($name, strtolower($tok))) {
                    return false;
                }
            }

            return true;
        }));
    }
}
