<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Concerns\UsesPostedSalesDocumentMetrics;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class RankingsReportRepository
{
    use UsesPostedSalesDocumentMetrics;

    public const TAB_CLIENTS = 'clients';

    public const TAB_ITEMS = 'items';

    public const TAB_SALESMEN = 'salesmen';

    public const TAB_CATEGORIES = 'categories';

    public const TAB_CITIES = 'cities';

    public const TAB_GROWING = 'growing';

    public const TAB_DECLINING = 'declining';

    /** @var list<string> */
    public const TABS = [
        self::TAB_CLIENTS,
        self::TAB_ITEMS,
        self::TAB_SALESMEN,
        self::TAB_CATEGORIES,
        self::TAB_CITIES,
        self::TAB_GROWING,
        self::TAB_DECLINING,
    ];

    /** @var list<string> */
    public const METRICS = ['amount', 'quantity', 'weight'];

    /** @var list<int> */
    public const LIMITS = [10, 25];

    public function __construct(
        private readonly VisitsReportRepository $visits,
        private readonly SalesReportRepository $sales,
    ) {}

    public function normalizeTab(string $tab): string
    {
        $tab = strtolower(trim($tab));

        return in_array($tab, self::TABS, true) ? $tab : self::TAB_CLIENTS;
    }

    public function normalizeMetric(string $metric): string
    {
        $metric = strtolower(trim($metric));

        return in_array($metric, self::METRICS, true) ? $metric : 'amount';
    }

    public function normalizeLimit(int $limit): int
    {
        return in_array($limit, self::LIMITS, true) ? $limit : 10;
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return array{
     *     rows: list<stdClass>,
     *     period_totals: stdClass,
     *     prior_period_label: string|null,
     *     prior_date_from: string|null,
     *     prior_date_to: string|null
     * }
     */
    public function getRankings(
        string $tab,
        string $dateFrom,
        string $dateTo,
        int $limit,
        string $metric,
        array $cities,
        array $salesmanIds,
        ?string $storage = null
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Rankings report requires SQL Server (sqlsrv).');
        }

        $tab = $this->normalizeTab($tab);
        $limit = $this->normalizeLimit($limit);
        $metric = $this->normalizeMetric($metric);

        $cities = $this->sales->normalizeCities($cities);
        $salesmanIds = $this->sales->normalizeSalesmanIds($salesmanIds);

        $periodTotals = $this->getPeriodTotals($dateFrom, $dateTo, $cities, $salesmanIds, $storage);

        if (in_array($tab, [self::TAB_GROWING, self::TAB_DECLINING], true)) {
            [$priorFrom, $priorTo, $priorLabel] = $this->priorPeriodRange($dateFrom, $dateTo);
            $rows = $this->fetchClientGrowthRows(
                $dateFrom,
                $dateTo,
                $priorFrom,
                $priorTo,
                $limit,
                $tab === self::TAB_GROWING,
                $cities,
                $salesmanIds,
                $storage
            );
            $this->attachSharePercent($rows, $periodTotals);

            return [
                'rows' => $rows,
                'period_totals' => $periodTotals,
                'prior_period_label' => $priorLabel,
                'prior_date_from' => $priorFrom,
                'prior_date_to' => $priorTo,
            ];
        }

        $rows = $this->fetchStandardRows(
            $tab,
            $dateFrom,
            $dateTo,
            $limit,
            $metric,
            $cities,
            $salesmanIds,
            $storage
        );
        $this->attachSharePercent($rows, $periodTotals);

        return [
            'rows' => $rows,
            'period_totals' => $periodTotals,
            'prior_period_label' => null,
            'prior_date_from' => null,
            'prior_date_to' => null,
        ];
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     */
    public function getPeriodTotals(
        string $dateFrom,
        string $dateTo,
        array $cities,
        array $salesmanIds,
        ?string $storage = null
    ): stdClass {
        $ctx = $this->queryContext();
        [$filterSql, $filterBindings] = $this->filterClauses($cities, $salesmanIds, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $filterBindings);

        $sql = "
            SELECT
                COALESCE(SUM({$ctx['lineAmountExpr']}), 0) AS amount,
                COALESCE(SUM({$ctx['lineQtyExpr']}), 0) AS quantity,
                COALESCE(SUM({$ctx['lineWeightExpr']}), 0) AS weight_total,
                COUNT(DISTINCT t.fld_store_document_title_id) AS invoice_count
            {$ctx['baseFrom']}
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$ctx['salesScopeSql']}
              {$filterSql}
        ";

        return DB::selectOne($sql, $bindings) ?? (object) [
            'amount' => 0,
            'quantity' => 0,
            'weight_total' => 0,
            'invoice_count' => 0,
        ];
    }

    /**
     * @return list<string>
     */
    public function getStorageOptions(): array
    {
        return $this->sales->getStorageOptions();
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public function priorPeriodRange(string $dateFrom, string $dateTo): array
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $days = $from->diffInDays($to);
        $priorTo = $from->copy()->subDay();
        $priorFrom = $priorTo->copy()->subDays($days);

        $priorFromStr = $priorFrom->toDateString();
        $priorToStr = $priorTo->toDateString();
        $label = $priorFrom->format('j M Y').' — '.$priorTo->format('j M Y');

        return [$priorFromStr, $priorToStr, $label];
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return list<stdClass>
     */
    private function fetchStandardRows(
        string $tab,
        string $dateFrom,
        string $dateTo,
        int $limit,
        string $metric,
        array $cities,
        array $salesmanIds,
        ?string $storage
    ): array {
        $ctx = $this->queryContext();
        [$filterSql, $filterBindings] = $this->filterClauses($cities, $salesmanIds, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $filterBindings);
        $orderExpr = $this->orderExpression($metric, $ctx);
        $config = $this->tabSqlConfig($tab, $ctx);

        $havingSql = $config['havingSql'] ?? '';
        $extraSelect = $config['extraSelect'] ?? '';

        $sql = "
            SELECT TOP ({$limit})
                {$config['selectSql']}
                {$extraSelect}
                COALESCE(SUM({$ctx['lineAmountExpr']}), 0) AS amount,
                COALESCE(SUM({$ctx['lineQtyExpr']}), 0) AS quantity,
                COALESCE(SUM({$ctx['lineWeightExpr']}), 0) AS weight_total,
                COUNT(DISTINCT t.fld_store_document_title_id) AS invoice_count
            {$ctx['baseFrom']}
            {$config['extraJoins']}
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$ctx['salesScopeSql']}
              {$filterSql}
            GROUP BY {$config['groupBy']}
            {$havingSql}
            ORDER BY {$orderExpr} DESC
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return list<stdClass>
     */
    private function fetchClientGrowthRows(
        string $dateFrom,
        string $dateTo,
        string $priorFrom,
        string $priorTo,
        int $limit,
        bool $growing,
        array $cities,
        array $salesmanIds,
        ?string $storage
    ): array {
        $ctx = $this->queryContext();
        [$filterSql, $filterBindings] = $this->filterClauses($cities, $salesmanIds, $storage);

        $currentBindings = array_merge([$dateFrom, $dateTo], $filterBindings);
        $priorBindings = array_merge([$priorFrom, $priorTo], $filterBindings);

        $clientGroup = '
            CAST(a.fld_account_id AS NVARCHAR(50)),
            COALESCE(a.fld_account_code, N\'\'),
            COALESCE(a.fld_account_name, t.fld_person_name, N\'(no account)\')
        ';

        $aggregateSql = function (string $fromParam, string $toParam) use ($ctx, $filterSql, $clientGroup): string {
            return "
                SELECT
                    CAST(a.fld_account_id AS NVARCHAR(50)) AS entity_id,
                    COALESCE(a.fld_account_code, N'') AS client_code,
                    COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS label,
                    COALESCE(SUM({$ctx['lineAmountExpr']}), 0) AS amount,
                    COALESCE(SUM({$ctx['lineQtyExpr']}), 0) AS quantity,
                    COALESCE(SUM({$ctx['lineWeightExpr']}), 0) AS weight_total,
                    COUNT(DISTINCT t.fld_store_document_title_id) AS invoice_count
                {$ctx['baseFrom']}
                WHERE CAST(t.fld_store_document_title_date AS date) >= CAST({$fromParam} AS date)
                  AND CAST(t.fld_store_document_title_date AS date) <= CAST({$toParam} AS date)
                  AND ISNULL(t.fld_is_cancelled, 0) = 0
                  AND ISNULL(d.fld_is_cancelled, 0) = 0
                  {$ctx['salesScopeSql']}
                  {$filterSql}
                GROUP BY {$clientGroup}
            ";
        };

        $compareSql = $growing ? ' AND cur.amount > prior.amount ' : ' AND cur.amount < prior.amount ';
        $orderSql = $growing ? ' growth_pct DESC ' : ' growth_pct ASC ';

        $sql = "
            SELECT TOP ({$limit})
                cur.entity_id,
                cur.client_code,
                cur.label,
                cur.amount,
                cur.quantity,
                cur.weight_total,
                cur.invoice_count,
                prior.amount AS prior_amount,
                prior.quantity AS prior_quantity,
                prior.weight_total AS prior_weight_total,
                CASE
                    WHEN prior.amount > 0 THEN ((cur.amount - prior.amount) / prior.amount) * 100.0
                    ELSE NULL
                END AS growth_pct
            FROM ({$aggregateSql('?', '?')}) AS cur
            INNER JOIN ({$aggregateSql('?', '?')}) AS prior
                ON prior.entity_id = cur.entity_id
            WHERE prior.amount > 0
              {$compareSql}
            ORDER BY {$orderSql}
        ";

        $bindings = array_merge($currentBindings, $priorBindings);

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<stdClass>  $rows
     */
    private function attachSharePercent(array $rows, stdClass $periodTotals): void
    {
        $totalAmount = (float) ($periodTotals->amount ?? 0);
        foreach ($rows as $row) {
            $amount = (float) ($row->amount ?? 0);
            $row->share_pct = $totalAmount > 0 ? ($amount / $totalAmount) * 100.0 : 0.0;
        }
    }

    /**
     * @return array{
     *     salesScopeSql: string,
     *     invoiceJoin: string,
     *     lineQtyExpr: string,
     *     lineAmountExpr: string,
     *     lineWeightExpr: string,
     *     baseFrom: string
     * }
     */
    private function queryContext(): array
    {
        $metrics = $this->postedSalesMetrics();
        $fragments = $metrics->metricFragments('w');
        $salesScopeSql = $metrics->postedSalesScopeSql(true);

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $nameCol = $this->bracketIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));

        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
        $itemLabelExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$nameCol} AS NVARCHAR(500)))), N''), NULLIF(LTRIM(RTRIM(CAST(i.{$descCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";

        $cityCol = $this->visits->getAccountCityColumnName();
        $cityExpr = "N'(no city)'";
        if (is_string($cityCol) && trim($cityCol) !== '') {
            $cityExpr = 'NULLIF(LTRIM(RTRIM(CAST(COALESCE(a.'.$this->bracketIdentifier($cityCol).", N'') AS NVARCHAR(500)))), N'')";
        }

        $weightSub = $fragments['weightSubquery'];

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$fragments['invoiceJoin']}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
        ";

        return [
            'salesScopeSql' => $salesScopeSql,
            'invoiceJoin' => $fragments['invoiceJoin'],
            'lineQtyExpr' => $fragments['lineQty'],
            'lineAmountExpr' => $fragments['lineAmount'],
            'lineWeightExpr' => $fragments['lineWeight'],
            'baseFrom' => $baseFrom,
            'categoryExpr' => $categoryExpr,
            'itemLabelExpr' => $itemLabelExpr,
            'cityExpr' => $cityExpr,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{selectSql: string, groupBy: string, extraJoins: string, extraSelect?: string, havingSql?: string}
     */
    private function tabSqlConfig(string $tab, array $ctx): array
    {
        return match ($tab) {
            self::TAB_ITEMS => [
                'selectSql' => "
                    CAST(d.fld_item_id_ref AS NVARCHAR(100)) AS entity_id,
                    {$ctx['itemLabelExpr']} AS label,
                    MAX({$ctx['categoryExpr']}) AS secondary_label,
                ",
                'groupBy' => "CAST(d.fld_item_id_ref AS NVARCHAR(100)), {$ctx['itemLabelExpr']}",
                'extraJoins' => '',
            ],
            self::TAB_SALESMEN => [
                'selectSql' => "
                    CAST(COALESCE(sm.fld_account_id, '00000000-0000-0000-0000-000000000000') AS NVARCHAR(50)) AS entity_id,
                    COALESCE(NULLIF(LTRIM(RTRIM(CAST(sm.fld_account_name AS NVARCHAR(500)))), N''), N'(no salesman)') AS label,
                    N'' AS secondary_label,
                ",
                'groupBy' => 'sm.fld_account_id, sm.fld_account_name',
                'extraJoins' => '
                    LEFT JOIN dbo.tbl_accounting_accounts AS sm
                        ON sm.fld_account_id = a.fld_sales_man_id_ref
                ',
            ],
            self::TAB_CATEGORIES => [
                'selectSql' => "
                    {$ctx['categoryExpr']} AS entity_id,
                    {$ctx['categoryExpr']} AS label,
                    N'' AS secondary_label,
                ",
                'groupBy' => $ctx['categoryExpr'],
                'extraJoins' => '',
            ],
            self::TAB_CITIES => [
                'selectSql' => "
                    {$ctx['cityExpr']} AS entity_id,
                    {$ctx['cityExpr']} AS label,
                    N'' AS secondary_label,
                ",
                'groupBy' => $ctx['cityExpr'],
                'extraJoins' => '',
                'havingSql' => " HAVING {$ctx['cityExpr']} IS NOT NULL AND LTRIM(RTRIM({$ctx['cityExpr']})) <> N'' ",
            ],
            default => [
                'selectSql' => "
                    CAST(a.fld_account_id AS NVARCHAR(50)) AS entity_id,
                    COALESCE(a.fld_account_code, N'') AS client_code,
                    COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS label,
                    N'' AS secondary_label,
                ",
                'groupBy' => '
                    a.fld_account_id,
                    a.fld_account_code,
                    a.fld_account_name,
                    t.fld_person_name
                ',
                'extraJoins' => '',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function orderExpression(string $metric, array $ctx): string
    {
        return match ($metric) {
            'quantity' => 'quantity',
            'weight' => 'weight_total',
            default => 'amount',
        };
    }

    /**
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return array{0: string, 1: list<string|float>}
     */
    private function filterClauses(array $cities, array $salesmanIds, ?string $storage): array
    {
        [$citySql, $cityBindings] = $this->visits->sqlFilterAccountCityEquals('a', $cities);

        $salesmanSql = '';
        $salesmanBindings = [];
        if ($salesmanIds !== []) {
            $placeholders = implode(',', array_fill(0, count($salesmanIds), 'CAST(? AS UNIQUEIDENTIFIER)'));
            $salesmanSql = ' AND a.fld_sales_man_id_ref IN ('.$placeholders.') ';
            $salesmanBindings = $salesmanIds;
        }

        $storageSql = '';
        $storageBindings = [];
        $storageValue = trim((string) ($storage ?? ''));
        if ($storageValue !== '') {
            $storageSql = ' AND LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N\'\') AS NVARCHAR(500)))) = ? ';
            $storageBindings[] = $storageValue;
        }

        return [
            $citySql.$salesmanSql.$storageSql,
            array_merge($cityBindings, $salesmanBindings, $storageBindings),
        ];
    }

    private function bracketIdentifier(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }
}
