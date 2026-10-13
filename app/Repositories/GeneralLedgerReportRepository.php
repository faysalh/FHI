<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

class GeneralLedgerReportRepository
{
    private const EMPTY_GUID = '00000000-0000-0000-0000-000000000000';

    private const DETAILS = 'dbo.tbl_documant_details';

    private const TITLES = 'dbo.tbl_document_titles';

    private const DOC_TYPES = 'dbo.tbl_documents_types';

    private const ACCOUNTS = 'dbo.tbl_accounting_accounts';

    private const ACCOUNT_DETAILS = 'dbo.tbl_accounting_account_details';

    private const CURRENCIES = 'dbo.tbl_common_currencies';

    private const YEARS = 'dbo.tbl_common_years';

    private const AGENTS = 'dbo.tbl_agents';

    private const AGENT_SUBTABLES = 'dbo.tbl_agent_subtables';

    private const STORE_TITLES = 'dbo.tbl_store_document_titles';

    private const STORE_FX = 'dbo.tbl_store_document_title_currency_rate';

    private const PAYMENT_TITLES = 'dbo.tbl_payment_document_titles';

    private const PAYMENT_FX = 'dbo.tbl_payment_document_title_currency_rate';

    private const PAYMENT_SUBTYPES = 'dbo.tbl_payment_document_subtypes';

    private const CURRENCY_HISTORY = 'dbo.tbl_common_currency_histories';

    public const MAX_EXPORT_ROWS = 10000;

    /**
     * @return list<stdClass>
     */
    public function getYearOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        try {
            return DB::select(
                'SELECT CAST(fld_year_id AS NVARCHAR(100)) AS year_id,
                        LTRIM(RTRIM(CAST(fld_year_name AS NVARCHAR(100)))) AS year_name,
                        CAST(fld_is_current AS bit) AS is_current
                 FROM '.self::YEARS.'
                 ORDER BY fld_start_date DESC'
            );
        } catch (Throwable $e) {
            Log::warning('general_ledger.years_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<stdClass>
     */
    public function getCurrencyOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        try {
            return DB::select(
                'SELECT CAST(fld_currency_id AS tinyint) AS currency_id,
                        LTRIM(RTRIM(CAST(ISNULL(fld_currency_symbol, CAST(fld_currency_id AS NVARCHAR(20))) AS NVARCHAR(50)))) AS currency_name
                 FROM '.self::CURRENCIES.'
                 ORDER BY fld_currency_id'
            );
        } catch (Throwable $e) {
            Log::warning('general_ledger.currencies_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    public function resolveCurrentYearId(): ?string
    {
        foreach ($this->getYearOptions() as $year) {
            if ((int) ($year->is_current ?? 0) === 1) {
                $id = trim((string) ($year->year_id ?? ''));

                return $id !== '' ? $id : null;
            }
        }

        return null;
    }

    /**
     * @return list<stdClass>
     */
    public function getTransactionTypeOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        try {
            return DB::select(
                'SELECT CAST(fld_document_type_id AS NVARCHAR(50)) AS type_id,
                        LTRIM(RTRIM(CAST(ISNULL(fld_document_type_desc, N\'\') AS NVARCHAR(200)))) AS type_name
                 FROM '.self::DOC_TYPES.'
                 ORDER BY fld_document_type_desc'
            );
        } catch (Throwable $e) {
            Log::warning('general_ledger.doc_types_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<stdClass>
     */
    public function getAgentOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        try {
            return DB::select(
                'SELECT CAST(fld_agent_id AS NVARCHAR(50)) AS agent_id,
                        LTRIM(RTRIM(CAST(ISNULL(fld_agent_name, N\'\') AS NVARCHAR(200)))) AS agent_name
                 FROM '.self::AGENTS.'
                 ORDER BY fld_agent_name'
            );
        } catch (Throwable $e) {
            Log::warning('general_ledger.agents_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array{id: string, code: string, name: string}>
     */
    public function searchAccounts(string $query, int $limit = 40): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $query = trim($query);
        $limit = max(1, min(80, $limit));

        try {
            if ($query === '') {
                $rows = DB::select(
                    'SELECT TOP ('.$limit.')
                            CAST(fld_account_id AS NVARCHAR(50)) AS id,
                            CAST(ISNULL(fld_account_code, 0) AS NVARCHAR(50)) AS code,
                            LTRIM(RTRIM(CAST(ISNULL(fld_account_name, N\'\') AS NVARCHAR(500)))) AS name
                     FROM '.self::ACCOUNTS.'
                     ORDER BY fld_account_name'
                );
            } else {
                $like = '%'.$query.'%';
                $rows = DB::select(
                    'SELECT TOP ('.$limit.')
                            CAST(fld_account_id AS NVARCHAR(50)) AS id,
                            CAST(ISNULL(fld_account_code, 0) AS NVARCHAR(50)) AS code,
                            LTRIM(RTRIM(CAST(ISNULL(fld_account_name, N\'\') AS NVARCHAR(500)))) AS name
                     FROM '.self::ACCOUNTS.'
                     WHERE CAST(fld_account_name AS NVARCHAR(500)) LIKE ?
                        OR CAST(fld_account_code AS NVARCHAR(50)) LIKE ?
                        OR CAST(fld_account_id AS NVARCHAR(50)) LIKE ?
                     ORDER BY fld_account_name',
                    [$like, $like, $like]
                );
            }
        } catch (Throwable $e) {
            Log::warning('general_ledger.account_search_failed', ['message' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (string) ($row->id ?? ''),
                'code' => (string) ($row->code ?? ''),
                'name' => (string) ($row->name ?? ''),
            ];
        }

        return $out;
    }

    public function resolveAccountLabel(?string $accountId): string
    {
        $accountId = $this->normalizeGuid($accountId);
        if ($accountId === null) {
            return '';
        }

        try {
            $row = DB::selectOne(
                'SELECT CAST(ISNULL(fld_account_code, 0) AS NVARCHAR(50)) AS code,
                        LTRIM(RTRIM(CAST(ISNULL(fld_account_name, N\'\') AS NVARCHAR(500)))) AS name
                 FROM '.self::ACCOUNTS.'
                 WHERE fld_account_id = CAST(? AS UNIQUEIDENTIFIER)',
                [$accountId]
            );
        } catch (Throwable) {
            return $accountId;
        }

        if ($row === null) {
            return $accountId;
        }

        $code = trim((string) ($row->code ?? ''));
        $name = trim((string) ($row->name ?? ''));

        return trim(($code !== '' ? $code.' — ' : '').$name);
    }

    public function normalizeGuid(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || strcasecmp($value, self::EMPTY_GUID) === 0) {
            return null;
        }

        if (preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $value) !== 1) {
            return null;
        }

        return strtoupper($value);
    }

    /**
     * Ledger lines matching AsanMax GeneralLedgerAccount.mrt (opening + period detail/summary).
     *
     * @return list<stdClass>
     */
    public function getLedgerRows(
        string $dateFrom,
        string $dateTo,
        ?string $yearId,
        ?string $accountId,
        ?string $salesmanId,
        int $currencyId,
        string $city,
        int $accountType,
        bool $showAsSummary,
        ?string $transactionTypeId,
        ?string $agentId,
        ?string $crossAccountId,
        ?string $paymentSubTypeId = null,
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            throw new RuntimeException('Choose a valid date range.');
        }

        if ($dateFrom > $dateTo) {
            throw new RuntimeException('Date from must be on or before date to.');
        }

        $yearId = $this->normalizeGuid($yearId) ?? self::EMPTY_GUID;
        $accountId = $this->normalizeGuid($accountId);
        $salesmanId = $this->normalizeGuid($salesmanId) ?? self::EMPTY_GUID;
        $transactionTypeId = $this->normalizeGuid($transactionTypeId) ?? self::EMPTY_GUID;
        $agentId = $this->normalizeGuid($agentId) ?? self::EMPTY_GUID;
        $crossAccountId = $this->normalizeGuid($crossAccountId);
        $paymentSubTypeId = $this->normalizeGuid($paymentSubTypeId) ?? self::EMPTY_GUID;
        $accountIdParam = $accountId ?? self::EMPTY_GUID;
        $crossAccountIdParam = $crossAccountId ?? self::EMPTY_GUID;
        $city = trim($city);
        $currencyId = max(0, min(255, $currencyId));
        $accountType = in_array($accountType, [0, 3, 5], true) ? $accountType : 0;
        $showAsSummary = $showAsSummary ? 1 : 0;

        $amountCredit = $this->amountExpression('det.fld_document_detail_credit');
        $amountDebit = $this->amountExpression('det.fld_document_detail_debit');
        $fxApply = $this->fxOuterApplySql();
        $paymentApply = $this->paymentOuterApplySql();

        $commonAccountFilters = '
            (p.account_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER) OR det.fld_account_id_ref = p.account_id)
            AND (p.salesman_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER) OR tit.fld_sales_man_id_ref = p.salesman_id)
            AND (tit.fld_year_id_ref = p.year_id OR p.year_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER))
            AND (p.city = N\'\' OR ISNULL(ac.fld_city, N\'\') = p.city)
            AND (
                det.fld_agent_id_ref IS NULL
                OR p.agent_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER)
                OR det.fld_agent_id_ref IN (
                    SELECT fld_agent_id_ref FROM '.self::AGENT_SUBTABLES.' WHERE fld_parent_agent_id_ref = p.agent_id
                )
            )
            AND (
                p.account_type = 0
                OR (p.account_type = 3 AND ac.fld_account_role_id_ref = 3)
                OR (p.account_type = 5 AND ac.fld_account_role_id_ref = 5)
            )
        ';

        $sql = '
WITH params AS (
    SELECT
        CAST(? AS date) AS date_from,
        CAST(? AS date) AS date_to,
        CAST(? AS UNIQUEIDENTIFIER) AS year_id,
        CAST(? AS UNIQUEIDENTIFIER) AS account_id,
        CAST(? AS UNIQUEIDENTIFIER) AS salesman_id,
        CAST(? AS tinyint) AS currency_id,
        CAST(? AS NVARCHAR(200)) AS city,
        CAST(? AS int) AS account_type,
        CAST(? AS bit) AS show_as_summary,
        CAST(? AS UNIQUEIDENTIFIER) AS transaction_type_id,
        CAST(? AS UNIQUEIDENTIFIER) AS agent_id,
        CAST(? AS UNIQUEIDENTIFIER) AS cross_account_id,
        CAST(? AS UNIQUEIDENTIFIER) AS payment_subtype_id,
        CAST(ISNULL((
            SELECT TOP 1 fld_exchange_rate
            FROM '.self::CURRENCIES.'
            WHERE fld_currency_id = CAST(? AS tinyint)
        ), 1) AS decimal(18,6)) AS exchange_rate_now
),
opening AS (
    SELECT
        SUM(ISNULL(('.$amountCredit.'), 0)) AS Credit,
        SUM(ISNULL(('.$amountDebit.'), 0)) AS Debit,
        ac.fld_account_name AS fld_account_name,
        CAST(N\'---\' AS NVARCHAR(100)) AS No,
        CAST(N\'Previous period\' AS NVARCHAR(200)) AS Type,
        2 AS Sort,
        ac.fld_account_code AS fld_account_code,
        acdet.fld_account_phone AS fld_account_phone,
        acdet.fld_account_address AS fld_account_address,
        ac.fld_account_id AS fld_account_id,
        CAST(NULL AS datetime) AS fld_document_title_register_date,
        CAST(N\'\' AS NVARCHAR(500)) AS CrossAccount,
        CAST(N\'\' AS NVARCHAR(500)) AS Description,
        CAST(N\'\' AS NVARCHAR(500)) AS NOTES,
        CAST(0 AS int) AS RowNo,
        CAST(1 AS decimal(18,6)) AS fld_currency_rate,
        CAST(NULL AS NVARCHAR(50)) AS fld_currency_symbol,
        CAST(1 AS decimal(18,6)) AS fld_currency_rate2,
        CAST(NULL AS NVARCHAR(50)) AS fld_currency_symbol2,
        CAST(N\'0\' AS NVARCHAR(1)) AS Flag,
        CAST(N\'\' AS NVARCHAR(100)) AS NO1,
        CAST(N\'\' AS NVARCHAR(100)) AS NO2,
        CAST(NULL AS UNIQUEIDENTIFIER) AS fld_agent_id_ref,
        CAST(NULL AS NVARCHAR(200)) AS Payment_Type
    FROM '.self::DETAILS.' det
    CROSS JOIN params p
    LEFT JOIN '.self::TITLES.' tit ON det.fld_document_title_id_ref = tit.fld_document_title_id
    LEFT JOIN '.self::DOC_TYPES.' TransactionTypeID ON tit.fld_document_type_id_ref = TransactionTypeID.fld_document_type_id
    LEFT JOIN '.self::ACCOUNTS.' ac ON det.fld_account_id_ref = ac.fld_account_id
    LEFT JOIN '.self::ACCOUNT_DETAILS.' acdet ON ac.fld_account_id = acdet.fld_account_id_ref
    '.$fxApply.'
    WHERE '.$commonAccountFilters.'
      AND CONVERT(date, tit.fld_document_title_register_date) < p.date_from
      AND p.cross_account_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER)
    GROUP BY
        ac.fld_account_id, ac.fld_account_code, acdet.fld_account_phone, acdet.fld_account_address, ac.fld_account_name
),
detail AS (
    SELECT
        ISNULL(('.$amountCredit.'), 0) AS Credit,
        ISNULL(('.$amountDebit.'), 0) AS Debit,
        ac.fld_account_name AS fld_account_name,
        CAST(ISNULL(tit.fld_document_title_no, N\'\') AS NVARCHAR(100)) AS No,
        CAST(TransactionTypeID.fld_document_type_desc AS NVARCHAR(200)) AS Type,
        3 AS Sort,
        ac.fld_account_code AS fld_account_code,
        acdet.fld_account_phone AS fld_account_phone,
        acdet.fld_account_address AS fld_account_address,
        ac.fld_account_id AS fld_account_id,
        tit.fld_document_title_register_date AS fld_document_title_register_date,
        CAST(ac2.fld_account_name AS NVARCHAR(500)) AS CrossAccount,
        CAST(det.fld_document_det_desc AS NVARCHAR(500)) AS Description,
        CAST(tit.fld_desc AS NVARCHAR(500)) AS NOTES,
        CAST(det.fld_docment_detail_row AS int) AS RowNo,
        det.fld_currency_rate AS fld_currency_rate,
        CAST(cur.fld_currency_symbol AS NVARCHAR(50)) AS fld_currency_symbol,
        det.fld_currency_rate AS fld_currency_rate2,
        CAST(cur.fld_currency_symbol AS NVARCHAR(50)) AS fld_currency_symbol2,
        CAST(N\'1\' AS NVARCHAR(1)) AS Flag,
        CAST(stit.fld_store_document_title_no1 AS NVARCHAR(100)) AS NO1,
        CAST(stit.fld_store_document_title_no2 AS NVARCHAR(100)) AS NO2,
        tit.fld_agent_id_ref AS fld_agent_id_ref,
        CAST(pst.fld_payment_title_subtype_name AS NVARCHAR(200)) AS Payment_Type
    FROM '.self::DETAILS.' det
    CROSS JOIN params p
    LEFT JOIN '.self::TITLES.' tit ON det.fld_document_title_id_ref = tit.fld_document_title_id
    LEFT JOIN '.self::DOC_TYPES.' TransactionTypeID ON tit.fld_document_type_id_ref = TransactionTypeID.fld_document_type_id
    LEFT JOIN '.self::ACCOUNTS.' ac ON det.fld_account_id_ref = ac.fld_account_id
    LEFT JOIN '.self::ACCOUNT_DETAILS.' acdet ON ac.fld_account_id = acdet.fld_account_id_ref
    LEFT JOIN '.self::ACCOUNTS.' ac2 ON det.fld_cross_account_id_ref = ac2.fld_account_id
    LEFT JOIN '.self::CURRENCIES.' cur ON det.fld_currency_id_ref = cur.fld_currency_id
    LEFT JOIN '.self::STORE_TITLES.' stit ON stit.fld_document_title_id_ref = det.fld_document_title_id_ref
    '.$paymentApply.'
    '.$fxApply.'
    WHERE p.show_as_summary = 0
      AND '.$commonAccountFilters.'
      AND (p.transaction_type_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER) OR tit.fld_document_type_id_ref = p.transaction_type_id)
      AND (
            p.cross_account_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER)
            OR det.fld_cross_account_id_ref = p.cross_account_id
      )
      AND CONVERT(date, tit.fld_document_title_register_date) >= p.date_from
      AND CONVERT(date, tit.fld_document_title_register_date) <= p.date_to
      AND (p.payment_subtype_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER) OR pst.fld_payment_title_subtype_id_ref = p.payment_subtype_id)
),
summary AS (
    SELECT
        SUM(ISNULL(('.$amountCredit.'), 0)) AS Credit,
        SUM(ISNULL(('.$amountDebit.'), 0)) AS Debit,
        ac.fld_account_name AS fld_account_name,
        CAST(ISNULL(tit.fld_document_title_no, N\'\') AS NVARCHAR(100)) AS No,
        CAST(TransactionTypeID.fld_document_type_desc AS NVARCHAR(200)) AS Type,
        3 AS Sort,
        ac.fld_account_code AS fld_account_code,
        acdet.fld_account_phone AS fld_account_phone,
        acdet.fld_account_address AS fld_account_address,
        ac.fld_account_id AS fld_account_id,
        tit.fld_document_title_register_date AS fld_document_title_register_date,
        CAST(N\'\' AS NVARCHAR(500)) AS CrossAccount,
        CAST(N\'\' AS NVARCHAR(500)) AS Description,
        CAST(tit.fld_desc AS NVARCHAR(500)) AS NOTES,
        CAST(0 AS int) AS RowNo,
        det.fld_currency_rate AS fld_currency_rate,
        CAST(cur.fld_currency_symbol AS NVARCHAR(50)) AS fld_currency_symbol,
        det.fld_currency_rate AS fld_currency_rate2,
        CAST(cur.fld_currency_symbol AS NVARCHAR(50)) AS fld_currency_symbol2,
        CAST(N\'1\' AS NVARCHAR(1)) AS Flag,
        CAST(stit.fld_store_document_title_no1 AS NVARCHAR(100)) AS NO1,
        CAST(stit.fld_store_document_title_no2 AS NVARCHAR(100)) AS NO2,
        tit.fld_agent_id_ref AS fld_agent_id_ref,
        CAST(pst.fld_payment_title_subtype_name AS NVARCHAR(200)) AS Payment_Type
    FROM '.self::DETAILS.' det
    CROSS JOIN params p
    LEFT JOIN '.self::TITLES.' tit ON det.fld_document_title_id_ref = tit.fld_document_title_id
    LEFT JOIN '.self::DOC_TYPES.' TransactionTypeID ON tit.fld_document_type_id_ref = TransactionTypeID.fld_document_type_id
    LEFT JOIN '.self::ACCOUNTS.' ac ON det.fld_account_id_ref = ac.fld_account_id
    LEFT JOIN '.self::ACCOUNT_DETAILS.' acdet ON ac.fld_account_id = acdet.fld_account_id_ref
    LEFT JOIN '.self::CURRENCIES.' cur ON det.fld_currency_id_ref = cur.fld_currency_id
    LEFT JOIN '.self::STORE_TITLES.' stit ON stit.fld_document_title_id_ref = det.fld_document_title_id_ref
    '.$paymentApply.'
    '.$fxApply.'
    WHERE p.show_as_summary = 1
      AND '.$commonAccountFilters.'
      AND (p.transaction_type_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER) OR tit.fld_document_type_id_ref = p.transaction_type_id)
      AND (
            p.cross_account_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER)
            OR det.fld_cross_account_id_ref = p.cross_account_id
      )
      AND CONVERT(date, tit.fld_document_title_register_date) >= p.date_from
      AND CONVERT(date, tit.fld_document_title_register_date) <= p.date_to
      AND (p.payment_subtype_id = CAST(\''.self::EMPTY_GUID.'\' AS UNIQUEIDENTIFIER) OR pst.fld_payment_title_subtype_id_ref = p.payment_subtype_id)
    GROUP BY
        ac.fld_account_name,
        ISNULL(tit.fld_document_title_no, N\'\'),
        TransactionTypeID.fld_document_type_desc,
        ac.fld_account_code,
        acdet.fld_account_phone,
        acdet.fld_account_address,
        ac.fld_account_id,
        tit.fld_document_title_register_date,
        tit.fld_desc,
        det.fld_currency_rate,
        cur.fld_currency_symbol,
        stit.fld_store_document_title_no1,
        stit.fld_store_document_title_no2,
        tit.fld_agent_id_ref,
        pst.fld_payment_title_subtype_name
),
ledger AS (
    SELECT * FROM opening
    UNION ALL
    SELECT * FROM detail
    UNION ALL
    SELECT * FROM summary
)
SELECT
    t.Credit AS credit,
    t.Debit AS debit,
    t.fld_account_name AS account_name,
    t.No AS document_no,
    t.Type AS document_type,
    t.Sort AS sort_order,
    t.fld_account_code AS account_code,
    t.fld_account_phone AS account_phone,
    t.fld_account_address AS account_address,
    CAST(t.fld_account_id AS NVARCHAR(50)) AS account_id,
    t.fld_document_title_register_date AS register_date,
    t.CrossAccount AS cross_account,
    t.Description AS description,
    t.NOTES AS notes,
    t.RowNo AS row_no,
    t.fld_currency_rate AS currency_rate,
    t.fld_currency_symbol AS currency_symbol,
    t.Flag AS flag,
    t.NO1 AS ref_no1,
    t.NO2 AS ref_no2,
    ag.fld_agent_name AS branch,
    t.Payment_Type AS payment_type
FROM ledger t
LEFT JOIN '.self::AGENTS.' ag ON ag.fld_agent_id = t.fld_agent_id_ref
ORDER BY
    t.fld_account_name ASC,
    t.Sort ASC,
    t.fld_document_title_register_date ASC,
    t.RowNo ASC
';

        $bindings = [
            $dateFrom,
            $dateTo,
            $yearId,
            $accountIdParam,
            $salesmanId,
            $currencyId,
            $city,
            $accountType,
            $showAsSummary,
            $transactionTypeId,
            $agentId,
            $crossAccountIdParam,
            $paymentSubTypeId,
            $currencyId,
        ];

        try {
            $rows = DB::select($sql, $bindings);
        } catch (Throwable $e) {
            Log::error('general_ledger.query_failed', ['message' => $e->getMessage()]);
            throw new RuntimeException('Unable to load general ledger: '.$e->getMessage(), 0, $e);
        }

        return $this->withRunningBalances($rows);
    }

    private function amountExpression(string $amountColumn): string
    {
        return '
            (
                CASE
                    WHEN p.currency_id = det.fld_currency_id_ref THEN '.$amountColumn.'
                    ELSE '.$amountColumn.' / NULLIF(det.fld_currency_rate, 0)
                END
            ) * CASE
                WHEN p.currency_id != 0 AND tit.fld_currency_id_ref = p.currency_id THEN 1
                ELSE ISNULL(x.fld_exchange_rate, p.exchange_rate_now)
            END
        ';
    }

    private function fxOuterApplySql(): string
    {
        return '
    OUTER APPLY (
        SELECT TOP 1 fx.fld_exchange_rate
        FROM (
            SELECT TOP 1 ch.fld_currency_rate AS fld_exchange_rate
            FROM '.self::STORE_FX.' ch
            LEFT JOIN '.self::STORE_TITLES.' ct ON ct.fld_store_document_title_id = ch.fld_store_doc_id_ref
            WHERE ct.fld_document_title_id_ref = tit.fld_document_title_id
              AND ch.fld_currency_id_ref = p.currency_id
            UNION ALL
            SELECT TOP 1 ch.fld_currency_rate AS fld_exchange_rate
            FROM '.self::PAYMENT_FX.' ch
            LEFT JOIN '.self::PAYMENT_TITLES.' ct ON ct.fld_payment_doc_title_id = ch.fld_payment_doc_id_ref
            WHERE ct.fld_doc_title_id_ref = tit.fld_document_title_id
              AND ch.fld_currency_id_ref = p.currency_id
            UNION ALL
            SELECT TOP 1 ch.fld_exchange_rate
            FROM '.self::CURRENCY_HISTORY.' ch
            WHERE ch.fld_currency_id_ref = p.currency_id
              AND ch.fld_date <= tit.fld_document_title_register_date
            ORDER BY ch.fld_date DESC
        ) fx
    ) x
        ';
    }

    private function paymentOuterApplySql(): string
    {
        return '
    OUTER APPLY (
        SELECT
            pt.fld_payment_title_subtype_id_ref,
            stt.fld_type_name AS fld_payment_title_subtype_name
        FROM '.self::PAYMENT_TITLES.' pt
        LEFT JOIN '.self::PAYMENT_SUBTYPES.' stt
            ON stt.fld_payment_title_subtype_id = pt.fld_payment_title_subtype_id_ref
        WHERE pt.fld_doc_title_id_ref = tit.fld_document_title_id
    ) pst
        ';
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<stdClass>
     */
    private function withRunningBalances(array $rows): array
    {
        $runningByAccount = [];
        $out = [];
        foreach ($rows as $row) {
            $accountId = (string) ($row->account_id ?? '');
            $debit = (float) ($row->debit ?? 0);
            $credit = (float) ($row->credit ?? 0);
            $runningByAccount[$accountId] = ($runningByAccount[$accountId] ?? 0.0) + ($debit - $credit);
            $row->balance = $runningByAccount[$accountId];
            $out[] = $row;
        }

        return $out;
    }
}
