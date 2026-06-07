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
                'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                [$schema, $table]
            );
        } elseif ($driver === 'mysql') {
            /** @var array<int, object{COLUMN_NAME:string, DATA_TYPE:string, IS_NULLABLE:string, CHARACTER_MAXIMUM_LENGTH:int|null}> $rows */
            $rows = DB::select(
                'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
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
            // Include common numeric/date scalar types so value search (e.g. invoice/document numbers) works too.
            $searchableTypes = [
                'varchar', 'nvarchar', 'char', 'nchar', 'text', 'ntext', 'longtext', 'mediumtext', 'uniqueidentifier',
                'int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'real', 'money', 'smallmoney',
                'date', 'datetime', 'datetime2', 'smalldatetime', 'time',
            ];
            $textCols = array_values(array_filter(
                $columns,
                static fn (array $c): bool => in_array(strtolower($c['data_type']), $searchableTypes, true)
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

                $this->orderSearchMatchesFirst($query, $textCols, $tokens, $driver);
            }
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Put exact value matches before broad substring matches so document numbers are easy to find.
     *
     * @param  array<int, array{name:string, data_type:string, is_nullable:string, max_length:int|null}>  $columns
     * @param  list<string>  $tokens
     */
    private function orderSearchMatchesFirst(Builder $query, array $columns, array $tokens, string $driver): void
    {
        if ($columns === [] || $tokens === []) {
            return;
        }

        $exactChecks = [];
        $likeChecks = [];
        $bindings = [];

        foreach ($tokens as $token) {
            foreach ($columns as $column) {
                $name = (string) $column['name'];
                $cast = $driver === 'sqlsrv'
                    ? 'CAST(['.str_replace(']', ']]', $name).'] AS NVARCHAR(MAX))'
                    : 'CAST(`'.str_replace('`', '``', $name).'` AS CHAR(16383))';

                $exactChecks[] = $cast.' = ?';
                $bindings[] = $token;
            }
        }

        foreach ($tokens as $token) {
            $pattern = '%'.$this->escapeLikePattern($token).'%';
            foreach ($columns as $column) {
                $name = (string) $column['name'];
                $cast = $driver === 'sqlsrv'
                    ? 'CAST(['.str_replace(']', ']]', $name).'] AS NVARCHAR(MAX))'
                    : 'CAST(`'.str_replace('`', '``', $name).'` AS CHAR(16383))';

                $likeChecks[] = $driver === 'sqlsrv'
                    ? $cast.' LIKE ? ESCAPE N\'\\\''
                    : 'LOWER('.$cast.') LIKE LOWER(?)';
                $bindings[] = $pattern;
            }
        }

        $query->orderByRaw(
            'CASE WHEN '.implode(' OR ', $exactChecks).' THEN 0 WHEN '.implode(' OR ', $likeChecks).' THEN 1 ELSE 2 END',
            $bindings
        );
    }

    /**
     * Read-only foreign-key relations between browsable business tables.
     *
     * @param  array<int, array{schema:string, table:string, full_name:string, column_count:int, row_count:int}>  $tables
     * @return list<array{
     *   constraint_name:string,
     *   schema:string,
     *   parent_table:string,
     *   parent_column:string,
     *   referenced_table:string,
     *   referenced_column:string
     * }>
     */
    public function listForeignKeyRelations(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $allowedFullNames = array_fill_keys(
            array_map(static fn (array $table): string => strtolower($table['full_name']), $tables),
            true
        );

        $driver = DB::getDriverName();
        if ($driver === 'sqlsrv') {
            /** @var array<int, object{
             *   constraint_name:string,
             *   schema_name:string,
             *   parent_table:string,
             *   parent_column:string,
             *   referenced_table:string,
             *   referenced_column:string
             * }> $rows */
            $rows = DB::select(
                "SELECT
                    fk.name AS constraint_name,
                    sch_parent.name AS schema_name,
                    t_parent.name AS parent_table,
                    c_parent.name AS parent_column,
                    t_ref.name AS referenced_table,
                    c_ref.name AS referenced_column
                 FROM sys.foreign_key_columns fkc
                 INNER JOIN sys.foreign_keys fk ON fk.object_id = fkc.constraint_object_id
                 INNER JOIN sys.tables t_parent ON t_parent.object_id = fkc.parent_object_id
                 INNER JOIN sys.schemas sch_parent ON sch_parent.schema_id = t_parent.schema_id
                 INNER JOIN sys.columns c_parent ON c_parent.object_id = fkc.parent_object_id AND c_parent.column_id = fkc.parent_column_id
                 INNER JOIN sys.tables t_ref ON t_ref.object_id = fkc.referenced_object_id
                 INNER JOIN sys.columns c_ref ON c_ref.object_id = fkc.referenced_object_id AND c_ref.column_id = fkc.referenced_column_id
                 WHERE sch_parent.name = N'dbo'
                 ORDER BY t_parent.name, fk.name, fkc.constraint_column_id"
            );

            $mapped = array_map(static fn (object $row): array => [
                'constraint_name' => (string) $row->constraint_name,
                'schema' => (string) $row->schema_name,
                'parent_table' => (string) $row->parent_table,
                'parent_column' => (string) $row->parent_column,
                'referenced_table' => (string) $row->referenced_table,
                'referenced_column' => (string) $row->referenced_column,
            ], $rows);

            return $this->filterRelationsByBrowsableTables($mapped, $allowedFullNames);
        }

        if ($driver === 'mysql') {
            $database = (string) DB::getDatabaseName();
            /** @var array<int, object{
             *   constraint_name:string,
             *   schema_name:string,
             *   parent_table:string,
             *   parent_column:string,
             *   referenced_table:string,
             *   referenced_column:string
             * }> $rows */
            $rows = DB::select(
                'SELECT
                    k.CONSTRAINT_NAME AS constraint_name,
                    k.TABLE_SCHEMA AS schema_name,
                    k.TABLE_NAME AS parent_table,
                    k.COLUMN_NAME AS parent_column,
                    k.REFERENCED_TABLE_NAME AS referenced_table,
                    k.REFERENCED_COLUMN_NAME AS referenced_column
                 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                 WHERE k.TABLE_SCHEMA = ?
                   AND k.REFERENCED_TABLE_NAME IS NOT NULL
                   AND k.REFERENCED_COLUMN_NAME IS NOT NULL
                 ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
                [$database]
            );

            $mapped = array_map(static fn (object $row): array => [
                'constraint_name' => (string) $row->constraint_name,
                'schema' => (string) $row->schema_name,
                'parent_table' => (string) $row->parent_table,
                'parent_column' => (string) $row->parent_column,
                'referenced_table' => (string) $row->referenced_table,
                'referenced_column' => (string) $row->referenced_column,
            ], $rows);

            return $this->filterRelationsByBrowsableTables($mapped, $allowedFullNames);
        }

        throw new RuntimeException('Unsupported database driver for schema browsing.');
    }

    /**
     * @param  list<array{
     *   constraint_name:string,
     *   schema:string,
     *   parent_table:string,
     *   parent_column:string,
     *   referenced_table:string,
     *   referenced_column:string
     * }>  $relations
     * @param  array<string, bool>  $allowedFullNames
     * @return list<array{
     *   constraint_name:string,
     *   schema:string,
     *   parent_table:string,
     *   parent_column:string,
     *   referenced_table:string,
     *   referenced_column:string
     * }>
     */
    private function filterRelationsByBrowsableTables(array $relations, array $allowedFullNames): array
    {
        return array_values(array_filter(
            $relations,
            static function (array $relation) use ($allowedFullNames): bool {
                $parentFullName = strtolower($relation['schema'].'.'.$relation['parent_table']);
                $referencedFullName = strtolower($relation['schema'].'.'.$relation['referenced_table']);

                return isset($allowedFullNames[$parentFullName]) && isset($allowedFullNames[$referencedFullName]);
            }
        ));
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
