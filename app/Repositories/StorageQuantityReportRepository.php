<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\ReportAssemblyPriorityService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

class StorageQuantityReportRepository
{
    private const MAX_EXPORT_ROWS = 2000;

    public function __construct(
        private readonly StorageReportRepository $storageReportRepository,
        private readonly ReportAssemblyPriorityService $assemblyPriority,
    ) {}

    /**
     * @return list<stdClass>
     */
    public function getYearOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        return DB::select(
            'SELECT CAST(fld_year_id AS NVARCHAR(100)) AS year_id,
                    LTRIM(RTRIM(CAST(fld_year_name AS NVARCHAR(100)))) AS year_name,
                    CAST(fld_is_current AS bit) AS is_current
             FROM dbo.tbl_common_years
             ORDER BY fld_start_date DESC'
        );
    }

    /**
     * @return list<string>
     */
    public function getStorageOptions(): array
    {
        return $this->storageReportRepository->getStorageOptions();
    }

    /**
     * @return list<string>
     */
    public function getCategoryOptions(): array
    {
        return $this->storageReportRepository->getCategoryOptions();
    }

    /**
     * @param  list<string>  $categories
     * @return list<stdClass>
     */
    public function getItemOptions(array $categories = []): array
    {
        return $this->storageReportRepository->getItemOptions($categories);
    }

    /**
     * @return list<stdClass>
     */
    public function getStoreTitleOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        try {
            $rows = DB::select(
                "SELECT TOP 200
                    CAST(fld_store_title_id AS NVARCHAR(100)) AS store_title_id,
                    LTRIM(RTRIM(CAST(COALESCE(fld_store_title_name, fld_title_name, fld_name, N'') AS NVARCHAR(500)))) AS store_title_name
                 FROM dbo.tbl_pda_store_title
                 WHERE fld_store_title_id IS NOT NULL
                 ORDER BY store_title_name"
            );
        } catch (Throwable $e) {
            Log::warning('storage_quantity.store_title_options_failed', ['message' => $e->getMessage()]);

            return [];
        }

        return array_values(array_filter($rows, static function (object $row): bool {
            return trim((string) ($row->store_title_name ?? '')) !== '';
        }));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getReport(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = $this->buildAllRows($filters);

        return $this->paginateRows($rows, $page, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<stdClass>
     */
    public function exportRows(array $filters): array
    {
        $rows = $this->buildAllRows($filters);

        return array_slice($rows, 0, self::MAX_EXPORT_ROWS);
    }

    /**
     * @param  list<stdClass>  $rows
     * @return array{balance_total: float, in_store_total: float}
     */
    public function totalsFromRows(array $rows): array
    {
        $balance = 0.0;
        $inStore = 0.0;
        foreach ($rows as $row) {
            $balance += (float) ($row->balance ?? 0);
            $inStore += (float) ($row->in_store ?? 0);
        }

        return [
            'balance_total' => $balance,
            'in_store_total' => $inStore,
        ];
    }

    /**
     * @param  list<string>|null  $values
     * @return list<string>
     */
    public function normalizeStringList(?array $values): array
    {
        return $this->storageReportRepository->normalizeStringList($values);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<stdClass>
     */
    private function buildAllRows(array $filters): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Storage quantity report requires SQL Server (sqlsrv).');
        }

        $mode = (string) ($filters['balance_mode'] ?? 'normal');
        $yearId = trim((string) ($filters['year_id'] ?? ''));
        if ($yearId === '') {
            throw new RuntimeException('Year is required.');
        }

        $storages = $this->normalizeStringList($filters['storages'] ?? []);
        $storeContexts = $this->resolveStoreContexts($storages);
        $candidates = $this->fetchItemCandidates($filters);
        $hideNegative = (bool) ($filters['hide_negative_balances'] ?? false);
        $hideZero = (bool) ($filters['hide_zero_balances'] ?? false);

        $rows = [];
        foreach ($candidates as $candidate) {
            foreach ($storeContexts as $storeContext) {
                $spRow = $this->executeBalanceSp($mode, $filters, $candidate, $storeContext);
                $balance = (float) ($spRow->balance ?? 0);
                if ($hideNegative && $balance < 0) {
                    continue;
                }
                if ($hideZero && abs($balance) < 0.0000001) {
                    continue;
                }
                $rows[] = $spRow;
            }
        }

        $rows = $this->assemblyPriority->sortRows($rows, 'category_name', 'item_name');
        usort($rows, static function (stdClass $a, stdClass $b): int {
            $categoryA = trim((string) ($a->category_name ?? ''));
            $categoryB = trim((string) ($b->category_name ?? ''));
            $itemA = trim((string) ($a->item_name ?? ''));
            $itemB = trim((string) ($b->item_name ?? ''));
            $itemIdA = trim((string) ($a->item_id ?? ''));
            $itemIdB = trim((string) ($b->item_id ?? ''));
            if ($categoryA !== $categoryB || $itemA !== $itemB || $itemIdA !== $itemIdB) {
                return 0;
            }

            return strcasecmp(
                trim((string) ($a->storage_name ?? '')),
                trim((string) ($b->storage_name ?? ''))
            );
        });

        return $rows;
    }

    /**
     * @param  list<string>  $storageNames
     * @return list<array{id: ?string, name: string}>
     */
    private function resolveStoreContexts(array $storageNames): array
    {
        if ($storageNames === []) {
            return [['id' => null, 'name' => 'All storages']];
        }

        $map = $this->storageNameToIdMap();
        $contexts = [];
        foreach ($storageNames as $name) {
            $id = $map[$name] ?? null;
            if ($id === null) {
                continue;
            }
            $contexts[] = ['id' => $id, 'name' => $name];
        }

        return $contexts !== [] ? $contexts : [['id' => null, 'name' => 'All storages']];
    }

    /**
     * @return array<string, string>
     */
    private function storageNameToIdMap(): array
    {
        $rows = DB::select(
            "SELECT CAST(fld_store_id AS NVARCHAR(100)) AS store_id,
                    LTRIM(RTRIM(CAST(fld_store_name AS NVARCHAR(500)))) AS store_name
             FROM dbo.tbl_stores
             WHERE fld_store_name IS NOT NULL"
        );
        $map = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->store_name ?? ''));
            $id = trim((string) ($row->store_id ?? ''));
            if ($name !== '' && $id !== '') {
                $map[$name] = $id;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<stdClass>
     */
    private function fetchItemCandidates(array $filters): array
    {
        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
        $itemNameExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";
        $itemCodeExpr = $this->itemCodeExpr('i');

        $categories = $this->normalizeStringList($filters['categories'] ?? []);
        $excludeCategories = $this->normalizeStringList($filters['exclude_categories'] ?? []);
        $items = $this->normalizeStringList($filters['items'] ?? []);
        $excludeItems = $this->normalizeStringList($filters['exclude_items'] ?? []);

        $bindings = [];
        $where = '';

        if ($categories !== []) {
            $placeholders = implode(', ', array_fill(0, count($categories), '?'));
            $where .= " AND {$categoryExpr} IN ({$placeholders}) ";
            array_push($bindings, ...$categories);
        }
        if ($excludeCategories !== []) {
            $placeholders = implode(', ', array_fill(0, count($excludeCategories), '?'));
            $where .= " AND {$categoryExpr} NOT IN ({$placeholders}) ";
            array_push($bindings, ...$excludeCategories);
        }
        if ($items !== []) {
            $placeholders = implode(', ', array_fill(0, count($items), '?'));
            $where .= ' AND CAST(i.'.$pkCol.' AS NVARCHAR(100)) IN ('.$placeholders.') ';
            array_push($bindings, ...$items);
        }
        if ($excludeItems !== []) {
            $placeholders = implode(', ', array_fill(0, count($excludeItems), '?'));
            $where .= ' AND CAST(i.'.$pkCol.' AS NVARCHAR(100)) NOT IN ('.$placeholders.') ';
            array_push($bindings, ...$excludeItems);
        }

        return DB::select(
            "SELECT
                CAST(i.{$pkCol} AS NVARCHAR(100)) AS item_id,
                CAST(al.fld_unit_id_ref AS NVARCHAR(100)) AS unit_id,
                {$itemNameExpr} AS item_name,
                {$categoryExpr} AS category_name,
                {$itemCodeExpr} AS item_code
             FROM {$itemsTable} AS i
             INNER JOIN dbo.tbl_store_item_all_units AS al
                ON al.fld_item_id_ref = i.{$pkCol}
               AND CAST(al.fld_unit_scale AS decimal(24, 6)) = 1
             WHERE 1 = 1
               {$where}
             ORDER BY {$categoryExpr} ASC, {$itemNameExpr} ASC",
            $bindings
        );
    }

    /**
     * @param  array{id: ?string, name: string}  $storeContext
     */
    private function executeBalanceSp(
        string $mode,
        array $filters,
        stdClass $candidate,
        array $storeContext
    ): stdClass {
        $storeTitleId = trim((string) ($filters['store_title_id'] ?? ''));
        $expirationDate = trim((string) ($filters['expiration_date'] ?? ''));
        $serial = trim((string) ($filters['serial'] ?? ''));
        $batchNo = trim((string) ($filters['batch_no'] ?? ''));

        $storeTitleParam = $storeTitleId !== '' ? $storeTitleId : null;
        $expirationParam = $expirationDate !== '' ? $expirationDate : null;
        $serialParam = $serial !== '' ? $serial : null;
        $batchParam = $batchNo !== '' ? $batchNo : null;
        $storeIdParam = $storeContext['id'];

        $balance = 0.0;
        $inStore = null;

        try {
            if ($mode === 'adv') {
                $asOf = $this->normalizeAdvDatetime((string) ($filters['as_of_datetime'] ?? ''));
                $sp = $this->validatedSpName('storage_quantity_sp_adv', 'dbo.SP_Get_Item_Balance_Adv');
                $result = DB::select(
                    'EXEC '.$sp.' @YearID=?, @ItemID=?, @Unit=?, @StoreTitleID=?, @ExpirationDate=?, @StoreID=?, @Date=?, @Serial=?',
                    [
                        (string) ($filters['year_id'] ?? ''),
                        (string) ($candidate->item_id ?? ''),
                        (string) ($candidate->unit_id ?? ''),
                        $storeTitleParam,
                        $expirationParam,
                        $storeIdParam,
                        $asOf,
                        $serialParam,
                    ]
                );
                $balance = (float) ($result[0]->Balance ?? 0);
            } else {
                $sp = $this->validatedSpName('storage_quantity_sp_normal', 'dbo.SP_Get_Item_Balance');
                $result = DB::select(
                    'EXEC '.$sp.' @YearID=?, @ItemID=?, @Unit=?, @StoreTitleID=?, @ExpirationDate=?, @StoreID=?, @Serial=?, @BatchNo=?',
                    [
                        (string) ($filters['year_id'] ?? ''),
                        (string) ($candidate->item_id ?? ''),
                        (string) ($candidate->unit_id ?? ''),
                        $storeTitleParam,
                        $expirationParam,
                        $storeIdParam,
                        $serialParam,
                        $batchParam,
                    ]
                );
                $balance = (float) ($result[0]->Balance ?? 0);
                $inStore = (float) ($result[0]->InStore ?? 0);
            }
        } catch (Throwable $e) {
            Log::warning('storage_quantity.sp_exec_failed', [
                'mode' => $mode,
                'item_id' => $candidate->item_id ?? null,
                'message' => $e->getMessage(),
            ]);
        }

        return (object) [
            'item_id' => (string) ($candidate->item_id ?? ''),
            'item_name' => (string) ($candidate->item_name ?? ''),
            'item_code' => (string) ($candidate->item_code ?? ''),
            'category_name' => (string) ($candidate->category_name ?? ''),
            'storage_name' => (string) ($storeContext['name'] ?? ''),
            'balance_mode' => $mode,
            'balance' => $balance,
            'in_store' => $inStore,
        ];
    }

    private function validatedSpName(string $configKey, string $default): string
    {
        $sp = trim((string) config('reporting.'.$configKey, $default));
        if (! preg_match('/^dbo\.[A-Za-z_][A-Za-z0-9_]*$/', $sp)) {
            throw new RuntimeException('Invalid stored procedure configuration.');
        }

        return $sp;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return LengthAwarePaginator<int, stdClass>
     */
    private function paginateRows(array $rows, int $page, int $perPage): LengthAwarePaginator
    {
        $perPage = max(1, min(250, $perPage));
        $page = max(1, $page);
        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);

        return new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    private function itemCodeExpr(string $alias): string
    {
        [$schema, $table] = $this->storeItemsTableSchemaAndName();
        $col = $this->resolveColumnName($schema, $table, [
            'fld_item_code',
            'fld_barcode',
            'fld_item_barcode',
            'fld_store_item_barcode',
        ]);
        if ($col === null) {
            return "N''";
        }

        return 'LTRIM(RTRIM(CAST(COALESCE('.$alias.'.'.$this->bracketSqlIdentifier($col).", N'') AS NVARCHAR(200))))";
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storeItemsTableSchemaAndName(): array
    {
        $full = trim((string) config('reporting.store_items_table', 'dbo.tbl_store_items'));
        $parts = explode('.', $full, 2);
        if (count($parts) === 2) {
            return [trim($parts[0], "[] \t\n\r\0\x0B"), trim($parts[1], "[] \t\n\r\0\x0B")];
        }

        return ['dbo', trim($full, "[] \t\n\r\0\x0B")];
    }

    /**
     * @param  list<string>  $candidates
     */
    private function resolveColumnName(string $schema, string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if ($this->columnExists($schema, $table, $column)) {
                return (string) $column;
            }
        }

        return null;
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT 1 AS ok FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$schema, $table, $column]
        );

        return $row !== null;
    }

    private function bracketSqlIdentifier(string $identifier): string
    {
        $identifier = trim(str_replace(['[', ']'], '', $identifier));

        return '['.str_replace(']', ']]', $identifier).']';
    }

    private function normalizeAdvDatetime(string $value): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            return now()->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return now()->format('Y-m-d H:i:s');
        }
    }
}
