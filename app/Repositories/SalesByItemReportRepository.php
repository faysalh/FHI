<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Concerns\UsesPostedSalesDocumentMetrics;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class SalesByItemReportRepository
{
    use UsesPostedSalesDocumentMetrics;

    private const MAX_EXPORT_ROWS = 10000;

    public function __construct(
        private readonly VisitsReportRepository $visits,
        private readonly SalesReportRepository $sales,
        private readonly SalesBySalesmanReportRepository $salesBySalesman,
    ) {}

    public function normalizeSalesmanId(?string $id): ?string
    {
        return $this->salesBySalesman->normalizeSalesmanId($id);
    }

    /**
     * @param  list<string>|null  $categories
     * @return list<string>
     */
    public function normalizeCategories(?array $categories): array
    {
        if ($categories === null || $categories === []) {
            return [];
        }
        $out = [];
        foreach ($categories as $category) {
            if (! is_string($category)) {
                continue;
            }
            $category = trim($category);
            if ($category === '' || mb_strlen($category) > 500) {
                continue;
            }
            $out[] = $category;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<int>|null  $tiers
     * @return list<int>
     */
    public function normalizePriceTiers(?array $tiers): array
    {
        if ($tiers === null || $tiers === []) {
            return [];
        }

        $out = [];
        foreach ($tiers as $tier) {
            if (! is_int($tier) && ! is_numeric($tier)) {
                continue;
            }
            $tier = (int) $tier;
            if ($tier >= 1 && $tier <= 5) {
                $out[] = $tier;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $categories
     * @param  list<int>  $priceTiers
     * @return list<string>
     */
    public function getCategoryOptions(
        string $dateFrom,
        string $dateTo,
        ?string $salesmanId,
        array $cities,
        ?string $storage,
        array $categories = [],
        array $priceTiers = []
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by item report requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));
        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = $this->categoryExpression('i', $descCol);

        $filter = $this->buildFilterClauses($salesmanId, $cities, $storage, $categories, $priceTiers);
        $bindings = array_merge([$dateFrom, $dateTo], $filter['bindings']);

        $sql = "
            SELECT DISTINCT {$categoryExpr} AS category_name
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$filter['sql']}
            ORDER BY category_name ASC
        ";

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->category_name ?? ''),
            DB::select($sql, $bindings)
        ));
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $categories
     * @param  list<int>  $priceTiers
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getReport(
        string $dateFrom,
        string $dateTo,
        string $salesmanId,
        array $cities,
        ?string $storage,
        array $categories,
        int $page,
        int $perPage,
        array $priceTiers = []
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by item report requires SQL Server (sqlsrv).');
        }

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $pivotSql = $this->pivotSelectSql();
        $lineTierSql = $this->lineTierSubquerySql($salesmanId, $cities, $storage, $categories, $priceTiers);
        $bindings = array_merge([$dateFrom, $dateTo], $lineTierSql['bindings']);

        $countSql = "
            SELECT COUNT(*) AS c FROM (
                SELECT 1 AS grp
                FROM ({$lineTierSql['sql']}) AS lt
                GROUP BY lt.category_name
            ) AS grp
        ";
        $total = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);
        $offset = max(0, ($page - 1) * $perPage);

        $dataSql = "
            SELECT
                lt.category_name,
                {$pivotSql}
            FROM ({$lineTierSql['sql']}) AS lt
            GROUP BY lt.category_name
            ORDER BY SUM(lt.line_amount) DESC
            OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY
        ";

        $items = DB::select($dataSql, $bindings);

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $categories
     * @param  list<int>  $priceTiers
     */
    public function getGrandTotals(
        string $dateFrom,
        string $dateTo,
        string $salesmanId,
        array $cities,
        ?string $storage,
        array $categories,
        array $priceTiers = []
    ): stdClass {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by item report requires SQL Server (sqlsrv).');
        }

        $pivotSql = $this->pivotSelectSql();
        $lineTierSql = $this->lineTierSubquerySql($salesmanId, $cities, $storage, $categories, $priceTiers);
        $bindings = array_merge([$dateFrom, $dateTo], $lineTierSql['bindings']);

        $sql = "
            SELECT {$pivotSql}
            FROM ({$lineTierSql['sql']}) AS lt
        ";

        $row = DB::selectOne($sql, $bindings);

        return $row ?? $this->emptyTotalsRow();
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $categories
     * @param  list<int>  $priceTiers
     * @return list<stdClass>
     */
    public function exportRows(
        string $dateFrom,
        string $dateTo,
        string $salesmanId,
        array $cities,
        ?string $storage,
        array $categories,
        array $priceTiers = []
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by item report requires SQL Server (sqlsrv).');
        }

        $pivotSql = $this->pivotSelectSql();
        $lineTierSql = $this->lineTierSubquerySql($salesmanId, $cities, $storage, $categories, $priceTiers);
        $bindings = array_merge([$dateFrom, $dateTo], $lineTierSql['bindings']);

        $sql = "
            SELECT
                lt.category_name,
                {$pivotSql}
            FROM ({$lineTierSql['sql']}) AS lt
            GROUP BY lt.category_name
            ORDER BY SUM(lt.line_amount) DESC
            OFFSET 0 ROWS FETCH NEXT ".self::MAX_EXPORT_ROWS.' ROWS ONLY
        ';

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $categories
     * @param  list<int>  $priceTiers
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function lineTierSubquerySql(
        ?string $salesmanId,
        array $cities,
        ?string $storage,
        array $categories,
        array $priceTiers = []
    ): array {
        $clientTierExpr = $this->salesBySalesman->clientPriceTierIndexSql();
        $tierJoin = $this->salesBySalesman->priceTierJoinSql();
        extract($this->postedSalesQueryContext('w', $clientTierExpr));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = $this->categoryExpression('i', $descCol);
        $filter = $this->buildFilterClauses($salesmanId, $cities, $storage, $categories, $priceTiers);

        $sql = "
            SELECT
                {$categoryExpr} AS category_name,
                {$clientTierExpr} AS price_tier,
                {$lineQtyExpr} AS line_qty,
                {$lineAmountExpr} AS line_amount,
                {$lineWeightExpr} AS line_weight
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            {$tierJoin}
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$filter['sql']}
        ";

        return [
            'sql' => $sql,
            'bindings' => $filter['bindings'],
        ];
    }

    private function pivotSelectSql(): string
    {
        $parts = [];
        for ($tier = 1; $tier <= 5; $tier++) {
            $parts[] = "COALESCE(SUM(CASE WHEN lt.price_tier = {$tier} THEN lt.line_qty ELSE 0 END), 0) AS p{$tier}_qty";
            $parts[] = "COALESCE(SUM(CASE WHEN lt.price_tier = {$tier} THEN lt.line_amount ELSE 0 END), 0) AS p{$tier}_amt";
            $parts[] = "COALESCE(SUM(CASE WHEN lt.price_tier = {$tier} THEN lt.line_weight ELSE 0 END), 0) AS p{$tier}_wt";
        }
        $parts[] = 'COALESCE(SUM(CASE WHEN lt.price_tier = 0 THEN lt.line_qty ELSE 0 END), 0) AS unmatched_qty';
        $parts[] = 'COALESCE(SUM(CASE WHEN lt.price_tier = 0 THEN lt.line_amount ELSE 0 END), 0) AS unmatched_amt';
        $parts[] = 'COALESCE(SUM(CASE WHEN lt.price_tier = 0 THEN lt.line_weight ELSE 0 END), 0) AS unmatched_wt';
        $parts[] = 'COALESCE(SUM(lt.line_qty), 0) AS total_qty';
        $parts[] = 'COALESCE(SUM(lt.line_amount), 0) AS total_amt';
        $parts[] = 'COALESCE(SUM(lt.line_weight), 0) AS total_wt';

        return implode(",\n                ", $parts);
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $categories
     * @param  list<int>  $priceTiers
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function buildFilterClauses(
        ?string $salesmanId,
        array $cities,
        ?string $storage,
        array $categories,
        array $priceTiers = []
    ): array {
        $sql = '';
        $bindings = [];

        $salesmanId = $this->normalizeSalesmanId($salesmanId);
        if ($salesmanId !== null) {
            $sql .= $this->salesBySalesman->salesmanFilterWhereSql();
            $bindings[] = $salesmanId;
        }

        $cityIds = $this->sales->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->visits->sqlFilterAccountCityEquals('a', $cityIds);
        $sql .= $citySql;
        $bindings = array_merge($bindings, $cityBindings);

        $storageValue = trim((string) ($storage ?? ''));
        if ($storageValue !== '') {
            $sql .= " AND LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500)))) = ? ";
            $bindings[] = $storageValue;
        }

        $categoryList = $this->normalizeCategories($categories);
        if ($categoryList !== []) {
            $placeholders = implode(',', array_fill(0, count($categoryList), '?'));
            $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
            $categoryExpr = $this->categoryExpression('i', $descCol);
            $sql .= " AND {$categoryExpr} IN ({$placeholders}) ";
            $bindings = array_merge($bindings, $categoryList);
        }

        $tierList = $this->normalizePriceTiers($priceTiers);
        if ($tierList !== []) {
            $clientTierExpr = $this->salesBySalesman->clientPriceTierIndexSql();
            $placeholders = implode(',', array_fill(0, count($tierList), '?'));
            $sql .= " AND {$clientTierExpr} IN ({$placeholders}) ";
            $bindings = array_merge($bindings, $tierList);
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    private function categoryExpression(string $itemAlias, string $descColBracketed): string
    {
        return 'COALESCE(NULLIF(LTRIM(RTRIM(CAST('.$itemAlias.'.'.$descColBracketed." AS NVARCHAR(500)))), N''), N'(uncategorized)')";
    }

    private function bracketSqlIdentifier(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }

    private function emptyTotalsRow(): stdClass
    {
        $row = new stdClass;
        for ($tier = 1; $tier <= 5; $tier++) {
            $row->{'p'.$tier.'_qty'} = 0;
            $row->{'p'.$tier.'_amt'} = 0;
            $row->{'p'.$tier.'_wt'} = 0;
        }
        $row->unmatched_qty = 0;
        $row->unmatched_amt = 0;
        $row->unmatched_wt = 0;
        $row->total_qty = 0;
        $row->total_amt = 0;
        $row->total_wt = 0;

        return $row;
    }
}
