<?php

declare(strict_types=1);

namespace App\Repositories;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

class VisitsReportRepository
{
    private const ACCOUNTS = 'dbo.tbl_accounting_accounts';

    private const MAX_EXPORT_ROWS = 10000;

    private static bool $cityColumnResolved = false;

    private static ?string $cityColumn = null;

    /**
     * @return list<array{id: string, name: string}>
     */
    public function getSalesmanOptions(): array
    {
        try {
            $rows = DB::select(
                '
                SELECT CAST(s.fld_account_id AS NVARCHAR(50)) AS id,
                       CAST(COALESCE(s.fld_account_name, N\'\') AS NVARCHAR(500)) AS name
                FROM '.self::ACCOUNTS.' AS s
                WHERE s.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                ORDER BY s.fld_account_name
                ',
                [IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID]
            );
        } catch (Throwable $e) {
            Log::warning('visits.salesman_options_failed', ['message' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (string) ($r->id ?? ''),
                'name' => (string) ($r->name ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Distinct city values for clients (accounts linked to a salesman per Identifier rules).
     *
     * @return list<string>
     */
    public function getCityOptions(): array
    {
        $col = $this->resolveAccountCityColumn();
        if ($col === null) {
            return [];
        }

        $cityBr = $this->bracketColumn($col);

        try {
            $rows = DB::select(
                '
                SELECT DISTINCT LTRIM(RTRIM(CAST(c.'.$cityBr.' AS NVARCHAR(500)))) AS city
                FROM '.self::ACCOUNTS.' AS c
                INNER JOIN '.self::ACCOUNTS.' AS s
                    ON s.fld_account_id = c.fld_sales_man_id_ref
                    AND s.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                WHERE c.'.$cityBr.' IS NOT NULL
                  AND LTRIM(RTRIM(CAST(c.'.$cityBr.' AS NVARCHAR(500)))) <> N\'\'
                ORDER BY city
                ',
                [IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID]
            );
        } catch (Throwable $e) {
            Log::warning('visits.city_options_failed', ['message' => $e->getMessage()]);

            return [];
        }

        $cities = [];
        foreach ($rows as $r) {
            $c = (string) ($r->city ?? '');
            if ($c !== '') {
                $cities[] = $c;
            }
        }

        return $cities;
    }

    /**
     * Resolved city column on {@code dbo.tbl_accounting_accounts}, or null if unknown.
     */
    public function getAccountCityColumnName(): ?string
    {
        return $this->resolveAccountCityColumn();
    }

    /**
     * SQL fragment + bindings to filter by client city on a joined {@code tbl_accounting_accounts} alias (e.g. {@code a}).
     * Empty {@code $cities} = no fragment (all cities). If no city column is configured, logs and returns no fragment.
     *
     * @param  list<string>  $cities
     * @return array{0: string, 1: list<string>}
     */
    public function sqlFilterAccountCityEquals(string $accountAlias, array $cities): array
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $accountAlias)) {
            throw new \InvalidArgumentException('Invalid SQL table alias for city filter.');
        }

        $cities = array_values(array_filter(array_map('trim', $cities), static fn (string $x): bool => $x !== ''));
        if ($cities === []) {
            return ['', []];
        }

        $cityCol = $this->resolveAccountCityColumn();
        if ($cityCol === null) {
            Log::warning('report.city_filter_skipped', [
                'reason' => 'No city column on tbl_accounting_accounts; set REPORTING_ACCOUNT_CITY_COLUMN.',
            ]);

            return ['', []];
        }

        $br = $this->bracketColumn($cityCol);
        $placeholders = implode(',', array_fill(0, count($cities), '?'));

        return [
            ' AND LTRIM(RTRIM(CAST('.$accountAlias.'.'.$br.' AS NVARCHAR(500)))) IN ('.$placeholders.') ',
            $cities,
        ];
    }

    /**
     * Calendar months touched by [dateFrom, dateTo], with intersection bounds for sales/visit checks.
     *
     * @return list<array{key: string, label: string, label_en: string, from: string, to: string, sql_alias: string}>
     */
    public function monthSegmentsInRange(string $dateFrom, string $dateTo): array
    {
        $start = Carbon::parse($dateFrom)->startOfDay();
        $end = Carbon::parse($dateTo)->endOfDay();

        if ($end->lt($start)) {
            $key = $start->format('Y-m');

            return [[
                'key' => $key,
                'label' => $start->locale(app()->getLocale())->translatedFormat('F Y'),
                'label_en' => $this->englishMonthYearLabel($start),
                'from' => $dateFrom,
                'to' => $dateTo,
                'sql_alias' => 'visit_'.str_replace('-', '_', $key),
            ]];
        }

        $segments = [];
        $cursor = $start->copy()->startOfMonth();
        $endMonth = $end->copy()->startOfMonth();

        while ($cursor->lte($endMonth)) {
            $segStart = $cursor->copy()->startOfMonth()->max($start);
            $segEnd = $cursor->copy()->endOfMonth()->min($end);
            if ($segStart->lte($segEnd)) {
                $key = $cursor->format('Y-m');
                $segments[] = [
                    'key' => $key,
                    'label' => $cursor->copy()->locale(app()->getLocale())->translatedFormat('F Y'),
                    'label_en' => $this->englishMonthYearLabel($cursor),
                    'from' => $segStart->toDateString(),
                    'to' => $segEnd->toDateString(),
                    'sql_alias' => 'visit_'.str_replace('-', '_', $key),
                ];
            }
            $cursor->addMonth();
        }

        if ($segments === []) {
            $key = $start->format('Y-m');

            return [[
                'key' => $key,
                'label' => $start->locale(app()->getLocale())->translatedFormat('F Y'),
                'label_en' => $this->englishMonthYearLabel($start),
                'from' => $dateFrom,
                'to' => $dateTo,
                'sql_alias' => 'visit_'.str_replace('-', '_', $key),
            ]];
        }

        return $segments;
    }

    private function englishMonthYearLabel(Carbon $monthInRange): string
    {
        $c = $monthInRange->copy()->startOfMonth()->locale('en');

        return $c->translatedFormat('F Y');
    }

    /**
     * @param  list<string>  $cities  Normalized city strings; empty = no city filter (all cities).
     */
    public function paginateVisits(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $salesmanAccountId,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        try {
            $count = $this->countVisits($cities, $salesmanAccountId);
            $items = $this->selectVisitsPage($dateFrom, $dateTo, $cities, $salesmanAccountId, $offset, $perPage);
        } catch (Throwable $e) {
            Log::error('visits.report_failed', ['message' => $e->getMessage()]);
            throw $e;
        }

        return new LengthAwarePaginator(
            $items,
            $count,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @return list<stdClass>
     */
    public function getVisitsForExport(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $salesmanAccountId
    ): array {
        return $this->selectVisitsPage($dateFrom, $dateTo, $cities, $salesmanAccountId, 0, self::MAX_EXPORT_ROWS);
    }

    /**
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    private function selectVisitsPage(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $salesmanAccountId,
        int $offset,
        int $limit
    ): array {
        [$sql, $bindings] = $this->buildSelectSql($dateFrom, $dateTo, $cities, $salesmanAccountId, $offset, $limit);

        /** @var list<stdClass> $rows */
        $rows = DB::select($sql, $bindings);

        return $rows;
    }

    /**
     * @param  list<string>  $cities
     */
    private function countVisits(
        array $cities,
        ?string $salesmanAccountId
    ): int {
        [$from, $whereBindings] = $this->clientFromWhere($cities, $salesmanAccountId);

        $sql = "
            SELECT COUNT(*) AS c
            FROM (
                SELECT c.fld_account_id
                {$from}
            ) AS v
        ";

        $row = DB::selectOne($sql, $whereBindings);

        return (int) ($row->c ?? 0);
    }

    /**
     * @param  list<string>  $cities
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildSelectSql(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $salesmanAccountId,
        int $offset,
        int $limit
    ): array {
        $cityCol = $this->resolveAccountCityColumn();

        [$from, $whereBindings] = $this->clientFromWhere($cities, $salesmanAccountId);

        $segments = $this->monthSegmentsInRange($dateFrom, $dateTo);
        $multiMonth = count($segments) > 1;

        if (! $multiMonth) {
            $seg = $segments[0];
            $visitColumnSql = 'CASE WHEN '.$this->visitExistsSubqueryForRange($seg['from'], $seg['to']).' THEN 1 ELSE 0 END AS visited';
        } else {
            $parts = [];
            foreach ($segments as $seg) {
                $alias = $seg['sql_alias'];
                $parts[] = 'CASE WHEN '.$this->visitExistsSubqueryForRange($seg['from'], $seg['to']).' THEN 1 ELSE 0 END AS ['.$alias.']';
            }
            $visitColumnSql = implode(",\n                ", $parts);
        }

        $cityExpr = $cityCol === null
            ? "N'' AS city"
            : 'LTRIM(RTRIM(CAST(c.'.$this->bracketColumn($cityCol).' AS NVARCHAR(500)))) AS city';

        $orderBy = $cityCol === null
            ? 'COALESCE(c.fld_account_name, N\'\')'
            : 'city, COALESCE(c.fld_account_name, N\'\')';

        $sql = "
            SELECT
                COALESCE(c.fld_account_code, N'') AS client_code,
                COALESCE(c.fld_account_name, N'') AS client_name,
                {$cityExpr},
                COALESCE(s.fld_account_name, N'') AS salesman_name,
                CAST(c.fld_account_id AS NVARCHAR(50)) AS client_account_id,
                {$visitColumnSql}
            {$from}
            ORDER BY {$orderBy}
            OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        // Only JOIN / city / salesman use ? placeholders. Visit dates are embedded as validated Y-m-d
        // literals so positional binding order cannot drift vs sqlsrv (SELECT EXISTS ? ? before FROM ?).
        $bindings = $whereBindings;

        return [$sql, $bindings];
    }

    /**
     * Correlated EXISTS for one date range. Dates are embedded, not bound: segment bounds are always
     * Y-m-d from {@see monthSegmentsInRange()} / validated request input, avoiding PDO/sqlsrv
     * placeholder ordering issues across SELECT vs FROM.
     */
    private function visitExistsSubqueryForRange(string $from, string $to): string
    {
        $fromLit = $this->assertIsoDateLiteral($from);
        $toLit = $this->assertIsoDateLiteral($to);

        return 'EXISTS (
                SELECT 1
                FROM dbo.tbl_store_document_detail AS d
                INNER JOIN dbo.tbl_store_document_titles AS t
                    ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
                WHERE t.fld_account_id_ref = c.fld_account_id
                  AND CAST(t.fld_store_document_title_date AS date) >= CAST(\''.$fromLit.'\' AS date)
                  AND CAST(t.fld_store_document_title_date AS date) <= CAST(\''.$toLit.'\' AS date)
                  AND ISNULL(t.fld_is_cancelled, 0) = 0
                  AND ISNULL(d.fld_is_cancelled, 0) = 0
            )';
    }

    private function assertIsoDateLiteral(string $date): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Expected Y-m-d date for SQL literal.');
        }

        return $date;
    }

    /**
     * @param  list<string>  $cities
     * @return array{0: string, 1: list<mixed>}
     */
    private function clientFromWhere(array $cities, ?string $salesmanAccountId): array
    {
        $cityCol = $this->resolveAccountCityColumn();
        $guid = IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID;

        $from = '
            FROM '.self::ACCOUNTS.' AS c
            INNER JOIN '.self::ACCOUNTS.' AS s
                ON s.fld_account_id = c.fld_sales_man_id_ref
                AND s.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
        ';

        $bindings = [$guid];

        $cities = array_values(array_filter(array_map('trim', $cities), static fn (string $x): bool => $x !== ''));
        if ($cities !== [] && $cityCol !== null) {
            $placeholders = implode(',', array_fill(0, count($cities), '?'));
            $br = $this->bracketColumn($cityCol);
            $from .= '
                WHERE LTRIM(RTRIM(CAST(c.'.$br.' AS NVARCHAR(500)))) IN ('.$placeholders.')
            ';
            foreach ($cities as $city) {
                $bindings[] = $city;
            }
        } elseif ($cities !== [] && $cityCol === null) {
            Log::warning('visits.city_filter_skipped', [
                'reason' => 'No city column found on tbl_accounting_accounts; set REPORTING_ACCOUNT_CITY_COLUMN or add a *city* column.',
            ]);
            $from .= ' WHERE 1 = 1 ';
        } else {
            $from .= ' WHERE 1 = 1 ';
        }

        $salesmanId = $salesmanAccountId !== null ? trim($salesmanAccountId) : '';
        if ($salesmanId !== '') {
            $from .= ' AND c.fld_sales_man_id_ref = ? ';
            $bindings[] = $salesmanId;
        }

        return [$from, $bindings];
    }

    /**
     * Resolved once per request (static cache).
     */
    private function resolveAccountCityColumn(): ?string
    {
        if (self::$cityColumnResolved) {
            return self::$cityColumn;
        }

        self::$cityColumnResolved = true;
        self::$cityColumn = null;

        if (DB::getDriverName() !== 'sqlsrv') {
            return null;
        }

        self::$cityColumn = $this->lookupAccountCityColumnOnSqlsrv();

        return self::$cityColumn;
    }

    private function lookupAccountCityColumnOnSqlsrv(): ?string
    {

        $candidates = [];

        $explicit = config('reporting.account_city_column');
        if (is_string($explicit) && trim($explicit) !== '' && $this->isSafeIdentifier($explicit)) {
            $candidates[] = trim($explicit);
        }

        $fallback = config('reporting.account_city_column_candidates', []);
        if (is_array($fallback)) {
            foreach ($fallback as $c) {
                if (is_string($c) && $this->isSafeIdentifier($c)) {
                    $candidates[] = $c;
                }
            }
        }

        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $col) {
            if ($this->columnExistsOnAccountingAccounts($col)) {
                Log::info('visits.city_column', ['column' => $col, 'source' => 'candidate']);

                return $col;
            }
        }

        $discovered = $this->discoverCityLikeColumn();
        if ($discovered !== null) {
            Log::info('visits.city_column', ['column' => $discovered, 'source' => 'discovered']);

            return $discovered;
        }

        Log::warning('visits.city_column', [
            'message' => 'No city column found; city filter disabled. Set REPORTING_ACCOUNT_CITY_COLUMN in .env to your column name.',
        ]);

        return null;
    }

    private function discoverCityLikeColumn(): ?string
    {
        try {
            $rows = DB::select(
                "SELECT COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = N'dbo'
                   AND TABLE_NAME = N'tbl_accounting_accounts'
                   AND COLUMN_NAME LIKE N'%city%'
                 ORDER BY COLUMN_NAME"
            );
        } catch (Throwable $e) {
            return null;
        }

        foreach ($rows as $row) {
            $name = (string) ($row->COLUMN_NAME ?? '');
            if ($this->isSafeIdentifier($name)) {
                return $name;
            }
        }

        return null;
    }

    private function columnExistsOnAccountingAccounts(string $column): bool
    {
        try {
            $row = DB::selectOne(
                'SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = N\'dbo\'
                   AND TABLE_NAME = N\'tbl_accounting_accounts\'
                   AND COLUMN_NAME = ?',
                [$column]
            );
        } catch (Throwable $e) {
            return false;
        }

        return $row !== null;
    }

    private function bracketColumn(string $col): string
    {
        return '['.str_replace(']', ']]', $col).']';
    }

    private function isSafeIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }
}
