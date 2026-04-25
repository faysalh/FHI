<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SchemaExplorerRepository
{
    /**
     * @var array<int, string>
     */
    private array $allowedKeywords = [
        'account',
        'client',
        'customer',
        'detail',
        'document',
        'invoice',
        'sales',
        'store',
    ];

    /**
     * @var array<int, string>
     */
    private array $blockedKeywords = [
        'sysdiagrams',
        'log',
        'logs',
        'error_log',
        'query_log',
        'sync_log',
        'common_log',
        'security',
        'user_profile',
        'version',
    ];

    /**
     * Columns hidden from schema browser (e.g. large binary image blobs), keyed by lowercase "schema.table".
     *
     * @var array<string, list<string>>
     */
    private const HIDDEN_COLUMNS_BY_TABLE = [
        'dbo.tbl_store_items' => ['fld_item_image'],
    ];

    /**
     * @return list<string>
     */
    private function hiddenColumnsForTable(string $schema, string $table): array
    {
        $key = strtolower($schema.'.'.$table);

        return self::HIDDEN_COLUMNS_BY_TABLE[$key] ?? [];
    }

    private function shouldHideColumn(string $schema, string $table, string $columnName): bool
    {
        foreach ($this->hiddenColumnsForTable($schema, $table) as $hidden) {
            if (strcasecmp($columnName, $hidden) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{
     *   schema: string,
     *   table: string,
     *   full_name: string,
     *   column_count: int,
     *   row_count: int
     * }>
     */
    public function listTables(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlsrv') {
            /** @var array<int, object{TABLE_SCHEMA:string, TABLE_NAME:string, column_count:int}> $rows */
            $rows = DB::select(
                "SELECT t.TABLE_SCHEMA, t.TABLE_NAME, COUNT(c.COLUMN_NAME) AS column_count
                 FROM INFORMATION_SCHEMA.TABLES t
                 INNER JOIN INFORMATION_SCHEMA.COLUMNS c
                    ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
                    AND c.TABLE_NAME = t.TABLE_NAME
                 WHERE t.TABLE_TYPE = 'BASE TABLE'
                 GROUP BY t.TABLE_SCHEMA, t.TABLE_NAME
                 ORDER BY t.TABLE_SCHEMA, t.TABLE_NAME"
            );

            $tables = array_map(
                static fn (object $row): array => [
                    'schema' => (string) $row->TABLE_SCHEMA,
                    'table' => (string) $row->TABLE_NAME,
                    'full_name' => (string) $row->TABLE_SCHEMA.'.'.(string) $row->TABLE_NAME,
                    'column_count' => (int) $row->column_count,
                ],
                $rows
            );

            return $this->filterTablesWithRows($tables);
        }

        if ($driver === 'mysql') {
            $database = (string) DB::getDatabaseName();
            /** @var array<int, object{TABLE_SCHEMA:string, TABLE_NAME:string, column_count:int}> $rows */
            $rows = DB::select(
                "SELECT t.TABLE_SCHEMA, t.TABLE_NAME, COUNT(c.COLUMN_NAME) AS column_count
                 FROM INFORMATION_SCHEMA.TABLES t
                 INNER JOIN INFORMATION_SCHEMA.COLUMNS c
                    ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
                    AND c.TABLE_NAME = t.TABLE_NAME
                 WHERE t.TABLE_TYPE = 'BASE TABLE'
                    AND t.TABLE_SCHEMA = ?
                 GROUP BY t.TABLE_SCHEMA, t.TABLE_NAME
                 ORDER BY t.TABLE_SCHEMA, t.TABLE_NAME",
                [$database]
            );

            $tables = array_map(
                static fn (object $row): array => [
                    'schema' => (string) $row->TABLE_SCHEMA,
                    'table' => (string) $row->TABLE_NAME,
                    'full_name' => (string) $row->TABLE_SCHEMA.'.'.(string) $row->TABLE_NAME,
                    'column_count' => (int) $row->column_count,
                ],
                $rows
            );

            return $this->filterTablesWithRows($tables);
        }

        throw new RuntimeException('Unsupported database driver for schema browsing.');
    }

    /**
     * Text search across dbo column and table names (any base table), for finding fields like sales_man_id_ref.
     *
     * @return list<array{schema: string, table: string, full_name: string, column: string, data_type: string}>
     */
    public function searchDboColumns(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        /** @var list<string> $tokens */
        $tokens = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return [];
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlsrv') {
            return $this->searchDboColumnsSqlsrv($tokens);
        }

        if ($driver === 'mysql') {
            return $this->searchDboColumnsMysql($tokens);
        }

        throw new RuntimeException('Unsupported database driver for schema browsing.');
    }

    /**
     * Case-insensitive substring match; multiple words must all match (column or table name).
     *
     * @param  list<string>  $tokens
     * @return list<array{schema: string, table: string, full_name: string, column: string, data_type: string}>
     */
    private function searchDboColumnsSqlsrv(array $tokens): array
    {
        $parts = [];
        $bindings = [];
        foreach ($tokens as $token) {
            $pattern = '%'.$this->escapeLikePattern($token).'%';
            $parts[] = '(LOWER(c.COLUMN_NAME) LIKE LOWER(?) ESCAPE N\'\\\' OR LOWER(c.TABLE_NAME) LIKE LOWER(?) ESCAPE N\'\\\')';
            $bindings[] = $pattern;
            $bindings[] = $pattern;
        }
        $whereTokens = implode(' AND ', $parts);

        /** @var array<int, object{TABLE_SCHEMA: string, TABLE_NAME: string, COLUMN_NAME: string, DATA_TYPE: string}> $rows */
        $rows = DB::select(
            "SELECT TOP 500 c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS c
             INNER JOIN INFORMATION_SCHEMA.TABLES t
                ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE t.TABLE_TYPE = N'BASE TABLE'
               AND t.TABLE_SCHEMA = N'dbo'
               AND ({$whereTokens})
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
            $bindings
        );

        $mapped = array_map(
            static fn (object $r): array => [
                'schema' => (string) $r->TABLE_SCHEMA,
                'table' => (string) $r->TABLE_NAME,
                'full_name' => (string) $r->TABLE_SCHEMA.'.'.(string) $r->TABLE_NAME,
                'column' => (string) $r->COLUMN_NAME,
                'data_type' => (string) $r->DATA_TYPE,
            ],
            $rows
        );

        return array_values(array_filter(
            $mapped,
            fn (array $row): bool => ! $this->shouldHideColumn($row['schema'], $row['table'], $row['column'])
        ));
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array{schema: string, table: string, full_name: string, column: string, data_type: string}>
     */
    private function searchDboColumnsMysql(array $tokens): array
    {
        $database = (string) DB::getDatabaseName();
        $parts = [];
        $bindings = [];
        foreach ($tokens as $token) {
            $pattern = '%'.$this->escapeLikePattern($token).'%';
            $parts[] = '(LOWER(c.COLUMN_NAME) LIKE LOWER(?) OR LOWER(c.TABLE_NAME) LIKE LOWER(?))';
            $bindings[] = $pattern;
            $bindings[] = $pattern;
        }
        $whereTokens = implode(' AND ', $parts);

        /** @var array<int, object{TABLE_SCHEMA: string, TABLE_NAME: string, COLUMN_NAME: string, DATA_TYPE: string}> $rows */
        $rows = DB::select(
            "SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS c
             INNER JOIN INFORMATION_SCHEMA.TABLES t
                ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE t.TABLE_TYPE = N'BASE TABLE'
               AND t.TABLE_SCHEMA = ?
               AND ({$whereTokens})
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
             LIMIT 500",
            array_merge([$database], $bindings)
        );

        $mapped = array_map(
            static fn (object $r): array => [
                'schema' => (string) $r->TABLE_SCHEMA,
                'table' => (string) $r->TABLE_NAME,
                'full_name' => (string) $r->TABLE_SCHEMA.'.'.(string) $r->TABLE_NAME,
                'column' => (string) $r->COLUMN_NAME,
                'data_type' => (string) $r->DATA_TYPE,
            ],
            $rows
        );

        return array_values(array_filter(
            $mapped,
            fn (array $row): bool => ! $this->shouldHideColumn($row['schema'], $row['table'], $row['column'])
        ));
    }

    private function escapeLikePattern(string $value): string
    {
        // SQL Server LIKE: [ is a wildcard set opener — double it for a literal bracket.
        $value = str_replace('[', '[[]', $value);

        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }

    /**
     * @return array<int, array{
     *   name: string,
     *   data_type: string,
     *   is_nullable: string,
     *   max_length: int|null
     * }>
     */
    public function getColumns(string $schema, string $table): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlsrv') {
            /** @var array<int, object{COLUMN_NAME:string, DATA_TYPE:string, IS_NULLABLE:string, CHARACTER_MAXIMUM_LENGTH:int|null}> $rows */
            $rows = DB::select(
                "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION",
                [$schema, $table]
            );
        } elseif ($driver === 'mysql') {
            /** @var array<int, object{COLUMN_NAME:string, DATA_TYPE:string, IS_NULLABLE:string, CHARACTER_MAXIMUM_LENGTH:int|null}> $rows */
            $rows = DB::select(
                "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION",
                [$schema, $table]
            );
        } else {
            throw new RuntimeException('Unsupported database driver for schema browsing.');
        }

        $mapped = array_map(
            static fn (object $row): array => [
                'name' => (string) $row->COLUMN_NAME,
                'data_type' => (string) $row->DATA_TYPE,
                'is_nullable' => (string) $row->IS_NULLABLE,
                'max_length' => $row->CHARACTER_MAXIMUM_LENGTH !== null ? (int) $row->CHARACTER_MAXIMUM_LENGTH : null,
            ],
            $rows
        );

        return array_values(array_filter(
            $mapped,
            fn (array $col): bool => ! $this->shouldHideColumn($schema, $table, $col['name'])
        ));
    }

    /**
     * Column names that appear in every browsable table (exact name match, read-only metadata).
     *
     * @param  array<int, array{schema:string, table:string, full_name:string, column_count:int, row_count:int}>  $tables
     * @return array<int, string>
     */
    public function getCommonColumnNamesAcrossAllTables(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $intersection = null;

        foreach ($tables as $table) {
            $names = array_map(
                static fn (array $column): string => $column['name'],
                $this->getColumns($table['schema'], $table['table'])
            );

            if ($intersection === null) {
                $intersection = $names;
            } else {
                $intersection = array_values(array_intersect($intersection, $names));
            }
        }

        sort($intersection);

        return $intersection;
    }

    /**
     * @param  array<int, array{schema:string, table:string, full_name:string, column_count:int, row_count:int}>  $tables
     * @return array{schema: string, table: string, full_name: string}|null
     */
    public function resolveTableSelection(?string $selectedTable, array $tables): ?array
    {
        if ($tables === []) {
            return null;
        }

        if ($selectedTable === null || trim($selectedTable) === '') {
            return [
                'schema' => $tables[0]['schema'],
                'table' => $tables[0]['table'],
                'full_name' => $tables[0]['full_name'],
            ];
        }

        $match = Collection::make($tables)
            ->first(static fn (array $table): bool => $table['full_name'] === $selectedTable);

        if (! is_array($match)) {
            return [
                'schema' => $tables[0]['schema'],
                'table' => $tables[0]['table'],
                'full_name' => $tables[0]['full_name'],
            ];
        }

        return [
            'schema' => $match['schema'],
            'table' => $match['table'],
            'full_name' => $match['full_name'],
        ];
    }

    /**
     * Sample rows; when {@see $searchQuery} is non-empty, only rows where every search token appears
     * in at least one string-like column (substring, case-insensitive via SQL).
     */
    public function getSampleRows(string $schema, string $table, int $perPage, ?string $searchQuery = null): LengthAwarePaginator
    {
        $visibleColumns = $this->getColumns($schema, $table);
        $columnNames = array_column($visibleColumns, 'name');

        $query = $this->tableQuery($schema, $table);
        if ($columnNames !== []) {
            $query->select($columnNames);
        }

        $trimmed = $searchQuery !== null ? trim($searchQuery) : '';
        /** @var list<string> $tokens */
        $tokens = $trimmed !== '' ? (preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];

        if ($tokens !== []) {
            $columns = $this->getColumns($schema, $table);
            // Include uniqueidentifier so sample-row search matches partial GUIDs (e.g. 2D2A670B vs full UUID).
            $textTypes = ['varchar', 'nvarchar', 'char', 'nchar', 'text', 'ntext', 'longtext', 'mediumtext', 'uniqueidentifier'];
            $textCols = array_values(array_filter(
                $columns,
                static fn (array $c): bool => in_array(strtolower($c['data_type']), $textTypes, true)
            ));

            if ($textCols === []) {
                $query->whereRaw('1 = 0');
            } else {
                $driver = DB::getDriverName();
                foreach ($tokens as $token) {
                    $pattern = '%'.$this->escapeLikePattern($token).'%';
                    $query->where(function ($q) use ($textCols, $pattern, $driver): void {
                        foreach ($textCols as $col) {
                            $name = $col['name'];
                            if ($driver === 'sqlsrv') {
                                $q->orWhereRaw('CAST(['.$name.'] AS NVARCHAR(MAX)) LIKE ? ESCAPE N\'\\\'', [$pattern]);
                            } else {
                                $q->orWhereRaw('LOWER(CAST(`'.$name.'` AS CHAR(16383))) LIKE LOWER(?)', [$pattern]);
                            }
                        }
                    });
                }
            }
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @param  array<int, array{schema:string, table:string, full_name:string, column_count:int}>  $tables
     * @return array<int, array{schema:string, table:string, full_name:string, column_count:int, row_count:int}>
     */
    private function filterTablesWithRows(array $tables): array
    {
        $result = [];

        foreach ($tables as $table) {
            if (! $this->isBrowsableBusinessTable($table['table'])) {
                continue;
            }

            $rowCount = $this->getRowCount($table['schema'], $table['table']);

            if ($rowCount < 1) {
                continue;
            }

            $table['row_count'] = $rowCount;
            $result[] = $table;
        }

        return $result;
    }

    private function isBrowsableBusinessTable(string $table): bool
    {
        $normalized = strtolower($table);

        foreach ($this->blockedKeywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return false;
            }
        }

        foreach ($this->allowedKeywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function getRowCount(string $schema, string $table): int
    {
        return $this->tableQuery($schema, $table)->count();
    }

    private function tableQuery(string $schema, string $table): Builder
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlsrv') {
            return DB::table(DB::raw(sprintf('[%s].[%s]', $schema, $table)));
        }

        if ($driver === 'mysql') {
            return DB::table(DB::raw(sprintf('`%s`.`%s`', $schema, $table)));
        }

        throw new RuntimeException('Unsupported database driver for schema browsing.');
    }
}
