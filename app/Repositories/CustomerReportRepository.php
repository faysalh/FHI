<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CustomerReportRepository
{
    /**
     * @var array<int, string>
     */
    private array $tableCandidates = ['customers', 'customer', 'mst_customer', 'tb_customer'];

    /**
     * @var array<string, array<int, string>>
     */
    private array $columnCandidates = [
        'id' => ['id', 'customer_id'],
        'customer_code' => ['customer_code', 'code', 'cust_code', 'customer_no'],
        'name' => ['name', 'customer_name', 'cust_name'],
        'phone' => ['phone', 'mobile', 'phone_number', 'telp'],
        'city' => ['city', 'address_city', 'kota'],
        'created_at' => ['created_at', 'created_date', 'createdon', 'inserted_at'],
    ];

    /**
     * @param  array{q?: string|null, city?: string|null, per_page?: int|null}  $filters
     * @return array{
     *   table: string,
     *   column_map: array<string, string>,
     *   paginator: LengthAwarePaginator
     * }
     */
    public function getCustomerReport(array $filters): array
    {
        [$table, $columnMap] = $this->discoverSchema();
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = DB::table($table);
        $this->applySelects($query, $columnMap);
        $this->applyFilters($query, $filters, $columnMap);
        $this->applySort($query, $columnMap);

        $paginator = $query->paginate($perPage)->withQueryString();

        return [
            'table' => $table,
            'column_map' => $columnMap,
            'paginator' => $paginator,
        ];
    }

    /**
     * @param  array{q?: string|null, city?: string|null, per_page?: int|null}  $filters
     * @return array{table: string, column_map: array<string, string>, rows: list<object>}
     */
    public function getCustomerRowsForExport(array $filters, int $maxRows = 10000): array
    {
        [$table, $columnMap] = $this->discoverSchema();
        $query = DB::table($table);
        $this->applySelects($query, $columnMap);
        $this->applyFilters($query, $filters, $columnMap);
        $this->applySort($query, $columnMap);

        return [
            'table' => $table,
            'column_map' => $columnMap,
            'rows' => $query->limit(max(1, min(50000, $maxRows)))->get()->all(),
        ];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    public function discoverSchema(): array
    {
        $table = $this->findCustomerTable();

        if ($table === null) {
            throw new RuntimeException('No supported customer table found.');
        }

        $columns = $this->getTableColumns($table);
        $columnMap = [];

        foreach ($this->columnCandidates as $alias => $candidates) {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $columnMap[$alias] = $candidate;
                    break;
                }
            }
        }

        if (! isset($columnMap['name']) && ! isset($columnMap['customer_code'])) {
            throw new RuntimeException('Customer table found, but required columns are missing.');
        }

        return [$table, $columnMap];
    }

    private function findCustomerTable(): ?string
    {
        $existingTables = $this->listTables();

        foreach ($this->tableCandidates as $candidate) {
            if (in_array($candidate, $existingTables, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function listTables(): array
    {
        $driver = DB::getDriverName();

        try {
            if ($driver === 'mysql') {
                $rows = DB::select('SHOW TABLES');
                $tables = [];

                foreach ($rows as $row) {
                    $tables[] = strtolower((string) array_values((array) $row)[0]);
                }

                return $tables;
            }

            if ($driver === 'sqlsrv') {
                $rows = DB::select("
                    SELECT TABLE_NAME
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_TYPE = 'BASE TABLE'
                ");

                return array_map(
                    static fn (object $row): string => strtolower((string) $row->TABLE_NAME),
                    $rows
                );
            }
        } catch (Throwable $exception) {
            Log::channel('db_health')->error('Schema discovery table listing failed.', [
                'db_connection' => DB::getDefaultConnection(),
                'db_driver' => $driver,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        throw new RuntimeException(sprintf('Unsupported DB driver for schema discovery: %s', $driver));
    }

    /**
     * @return array<int, string>
     */
    private function getTableColumns(string $table): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(sprintf('SHOW COLUMNS FROM `%s`', $table));

            return array_map(
                static fn (object $row): string => strtolower((string) $row->Field),
                $rows
            );
        }

        if ($driver === 'sqlsrv') {
            $rows = DB::select("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = ?
            ", [$table]);

            return array_map(
                static fn (object $row): string => strtolower((string) $row->COLUMN_NAME),
                $rows
            );
        }

        throw new RuntimeException(sprintf('Unsupported DB driver for column discovery: %s', $driver));
    }

    /**
     * @param  array<string, string>  $columnMap
     */
    private function applySelects(Builder $query, array $columnMap): void
    {
        $selects = [];

        foreach ($columnMap as $alias => $column) {
            $selects[] = sprintf('%s as %s', $column, $alias);
        }

        $query->select($selects);
    }

    /**
     * @param  array{q?: string|null, city?: string|null}  $filters
     * @param  array<string, string>  $columnMap
     */
    private function applyFilters(Builder $query, array $filters, array $columnMap): void
    {
        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $columnMap): void {
                if (isset($columnMap['name'])) {
                    $builder->orWhere($columnMap['name'], 'like', '%'.$search.'%');
                }

                if (isset($columnMap['customer_code'])) {
                    $builder->orWhere($columnMap['customer_code'], 'like', '%'.$search.'%');
                }
            });
        }

        $city = trim((string) ($filters['city'] ?? ''));
        if ($city !== '' && isset($columnMap['city'])) {
            $query->where($columnMap['city'], $city);
        }
    }

    /**
     * @param  array<string, string>  $columnMap
     */
    private function applySort(Builder $query, array $columnMap): void
    {
        if (isset($columnMap['created_at'])) {
            $query->orderByDesc($columnMap['created_at']);

            return;
        }

        if (isset($columnMap['id'])) {
            $query->orderByDesc($columnMap['id']);
        }
    }
}
