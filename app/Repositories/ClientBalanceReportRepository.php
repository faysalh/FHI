<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

class ClientBalanceReportRepository
{
    private const ACCOUNTS = 'dbo.tbl_accounting_accounts';

    private const SUBTABLES = 'dbo.tbl_accounting_account_subtables';

    /** Spelling matches AsanAccounting (tbl_documant_details). */
    private const DOCUMENT_DETAILS = 'dbo.tbl_documant_details';

    private const DOCUMENT_TITLES = 'dbo.tbl_document_titles';

    private const YEARS = 'dbo.tbl_common_years';

    private const CURRENCIES = 'dbo.tbl_common_currencies';

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
            Log::warning('client_balance.years_failed', ['message' => $e->getMessage()]);

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
                        LTRIM(RTRIM(CAST(fld_currency_id AS NVARCHAR(20)))) AS currency_name
                 FROM '.self::CURRENCIES.'
                 ORDER BY fld_currency_id'
            );
        } catch (Throwable $e) {
            Log::warning('client_balance.currencies_failed', ['message' => $e->getMessage()]);

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

    public function normalizeGuid(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $value) !== 1) {
            return null;
        }

        return strtoupper($value);
    }

    /**
     * Client balances for one salesman, matching dbo.SP_Get_Account_Balance logic.
     *
     * @return list<stdClass>
     */
    public function getBalancesForSalesman(string $salesmanId, ?string $yearId, int $currency = 0, bool $hideZero = false): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $salesmanId = $this->normalizeGuid($salesmanId);
        if ($salesmanId === null) {
            throw new RuntimeException('Choose a valid salesman.');
        }

        $yearId = $this->normalizeGuid($yearId) ?? $this->resolveCurrentYearId();
        if ($yearId === null) {
            throw new RuntimeException('No fiscal year is available.');
        }

        $currency = max(0, min(255, $currency));

        try {
            $rows = $currency === 0
                ? $this->balancesForSalesmanBaseCurrency($salesmanId, $yearId)
                : $this->balancesForSalesmanCurrency($salesmanId, $yearId, $currency);
        } catch (Throwable $e) {
            Log::warning('client_balance.batch_failed', ['message' => $e->getMessage()]);

            // Last resort: call SP_Get_Account_Balance per client.
            $rows = $this->balancesForSalesmanViaSp($salesmanId, $yearId, $currency);
        }

        if ($hideZero) {
            $rows = array_values(array_filter(
                $rows,
                static fn (stdClass $row): bool => abs((float) ($row->balance ?? 0)) >= 0.0000005
            ));
        }

        return $rows;
    }

    /**
     * @return list<stdClass>
     */
    private function balancesForSalesmanBaseCurrency(string $salesmanId, string $yearId): array
    {
        return DB::select(
            '
            SELECT CAST(c.fld_account_id AS NVARCHAR(50)) AS client_id,
                   CAST(COALESCE(c.fld_account_code, N\'\') AS NVARCHAR(120)) AS client_code,
                   CAST(COALESCE(c.fld_account_name, N\'\') AS NVARCHAR(500)) AS client_name,
                   CAST(c.fld_sales_man_id_ref AS NVARCHAR(50)) AS salesman_id,
                   CAST(COALESCE(sm.fld_account_name, N\'\') AS NVARCHAR(500)) AS salesman_name,
                   CAST(ISNULL((
                        SELECT (
                            SUM(ISNULL(det.fld_document_detail_debit, 0) / NULLIF(det.fld_currency_rate, 0))
                            - SUM(ISNULL(det.fld_document_detail_credit, 0) / NULLIF(det.fld_currency_rate, 0))
                        )
                        FROM '.self::DOCUMENT_DETAILS.' AS det
                        LEFT JOIN '.self::DOCUMENT_TITLES.' AS tit
                            ON det.fld_document_title_id_ref = tit.fld_document_title_id
                        WHERE tit.fld_year_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                          AND det.fld_account_id_ref IN (
                              SELECT sub.fld_account_id_ref
                              FROM '.self::SUBTABLES.' AS sub
                              WHERE sub.fld_parent_account_id_ref = c.fld_account_id
                          )
                   ), 0) AS float) AS balance
            FROM '.self::ACCOUNTS.' AS c
            INNER JOIN '.self::ACCOUNTS.' AS sm
                ON sm.fld_account_id = c.fld_sales_man_id_ref
               AND sm.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
            WHERE c.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
            ORDER BY c.fld_account_name, c.fld_account_code
            ',
            [$yearId, IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID, $salesmanId]
        );
    }

    /**
     * @return list<stdClass>
     */
    private function balancesForSalesmanCurrency(string $salesmanId, string $yearId, int $currency): array
    {
        $rateRow = DB::selectOne(
            'SELECT CAST(fld_exchange_rate AS float) AS exchange_rate
             FROM '.self::CURRENCIES.'
             WHERE fld_currency_id = ?',
            [$currency]
        );
        $rate = (float) ($rateRow->exchange_rate ?? 0);

        return DB::select(
            '
            SELECT CAST(c.fld_account_id AS NVARCHAR(50)) AS client_id,
                   CAST(COALESCE(c.fld_account_code, N\'\') AS NVARCHAR(120)) AS client_code,
                   CAST(COALESCE(c.fld_account_name, N\'\') AS NVARCHAR(500)) AS client_name,
                   CAST(c.fld_sales_man_id_ref AS NVARCHAR(50)) AS salesman_id,
                   CAST(COALESCE(sm.fld_account_name, N\'\') AS NVARCHAR(500)) AS salesman_name,
                   CAST((
                        ISNULL((
                            SELECT (
                                SUM(ISNULL(det.fld_document_detail_debit, 0) / NULLIF(det.fld_currency_rate, 0))
                                - SUM(ISNULL(det.fld_document_detail_credit, 0) / NULLIF(det.fld_currency_rate, 0))
                            )
                            FROM '.self::DOCUMENT_DETAILS.' AS det
                            LEFT JOIN '.self::DOCUMENT_TITLES.' AS tit
                                ON det.fld_document_title_id_ref = tit.fld_document_title_id
                            WHERE tit.fld_year_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                              AND det.fld_currency_id_ref <> ?
                              AND det.fld_account_id_ref IN (
                                  SELECT sub.fld_account_id_ref
                                  FROM '.self::SUBTABLES.' AS sub
                                  WHERE sub.fld_parent_account_id_ref = c.fld_account_id
                              )
                        ), 0) * ?
                        +
                        ISNULL((
                            SELECT (
                                SUM(ISNULL(det.fld_document_detail_debit, 0))
                                - SUM(ISNULL(det.fld_document_detail_credit, 0))
                            )
                            FROM '.self::DOCUMENT_DETAILS.' AS det
                            LEFT JOIN '.self::DOCUMENT_TITLES.' AS tit
                                ON det.fld_document_title_id_ref = tit.fld_document_title_id
                            WHERE tit.fld_year_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                              AND det.fld_currency_id_ref = ?
                              AND det.fld_account_id_ref IN (
                                  SELECT sub.fld_account_id_ref
                                  FROM '.self::SUBTABLES.' AS sub
                                  WHERE sub.fld_parent_account_id_ref = c.fld_account_id
                              )
                        ), 0)
                   ) AS float) AS balance
            FROM '.self::ACCOUNTS.' AS c
            INNER JOIN '.self::ACCOUNTS.' AS sm
                ON sm.fld_account_id = c.fld_sales_man_id_ref
               AND sm.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
            WHERE c.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
            ORDER BY c.fld_account_name, c.fld_account_code
            ',
            [
                $yearId,
                $currency,
                $rate,
                $yearId,
                $currency,
                IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID,
                $salesmanId,
            ]
        );
    }

    /**
     * @return list<stdClass>
     */
    private function balancesForSalesmanViaSp(string $salesmanId, string $yearId, int $currency): array
    {
        $clients = $this->listClientsForSalesman($salesmanId);
        $rows = [];
        foreach ($clients as $client) {
            $rows[] = (object) [
                'client_id' => (string) $client->client_id,
                'client_code' => (string) ($client->client_code ?? ''),
                'client_name' => (string) ($client->client_name ?? ''),
                'salesman_id' => $salesmanId,
                'salesman_name' => (string) ($client->salesman_name ?? ''),
                'balance' => $this->accountBalanceViaSp((string) $client->client_id, $yearId, $currency),
            ];
        }

        return $rows;
    }

    /**
     * @return list<stdClass>
     */
    public function listClientsForSalesman(string $salesmanId): array
    {
        $salesmanId = $this->normalizeGuid($salesmanId);
        if ($salesmanId === null) {
            return [];
        }

        try {
            return DB::select(
                '
                SELECT CAST(c.fld_account_id AS NVARCHAR(50)) AS client_id,
                       CAST(COALESCE(c.fld_account_code, N\'\') AS NVARCHAR(120)) AS client_code,
                       CAST(COALESCE(c.fld_account_name, N\'\') AS NVARCHAR(500)) AS client_name,
                       CAST(COALESCE(s.fld_account_name, N\'\') AS NVARCHAR(500)) AS salesman_name
                FROM '.self::ACCOUNTS.' AS c
                INNER JOIN '.self::ACCOUNTS.' AS s
                    ON s.fld_account_id = c.fld_sales_man_id_ref
                   AND s.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                WHERE c.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                ORDER BY c.fld_account_name, c.fld_account_code
                ',
                [IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID, $salesmanId]
            );
        } catch (Throwable $e) {
            Log::warning('client_balance.clients_failed', ['message' => $e->getMessage()]);

            throw new RuntimeException('Unable to load clients for the selected salesman.');
        }
    }

    public function accountBalanceViaSp(string $accountId, string $yearId, int $currency = 0): float
    {
        $accountId = $this->normalizeGuid($accountId);
        $yearId = $this->normalizeGuid($yearId);
        if ($accountId === null || $yearId === null) {
            return 0.0;
        }

        $sp = $this->validatedSpName();

        try {
            $result = DB::select(
                'EXEC '.$sp.' @AccountID=?, @YearID=?, @Currency=?',
                [$accountId, $yearId, $currency]
            );
        } catch (Throwable $e) {
            Log::warning('client_balance.sp_exec_failed', [
                'account_id' => $accountId,
                'year_id' => $yearId,
                'currency' => $currency,
                'message' => $e->getMessage(),
            ]);

            // Fallback mirrors SP_Get_Account_Balance when EXEC fails (permissions / naming).
            return $this->accountBalanceSetBased($accountId, $yearId, $currency);
        }

        $row = $result[0] ?? null;
        if ($row === null) {
            return 0.0;
        }

        $vars = get_object_vars($row);
        if ($vars === []) {
            return 0.0;
        }

        return (float) reset($vars);
    }

    /**
     * Set-based equivalent of dbo.SP_Get_Account_Balance for one account.
     */
    public function accountBalanceSetBased(string $accountId, string $yearId, int $currency = 0): float
    {
        $accountId = $this->normalizeGuid($accountId);
        $yearId = $this->normalizeGuid($yearId);
        if ($accountId === null || $yearId === null) {
            return 0.0;
        }

        try {
            if ($currency === 0) {
                $row = DB::selectOne(
                    '
                    SELECT (
                        SUM(ISNULL(det.fld_document_detail_debit, 0) / NULLIF(det.fld_currency_rate, 0))
                        - SUM(ISNULL(det.fld_document_detail_credit, 0) / NULLIF(det.fld_currency_rate, 0))
                    ) AS balance
                    FROM '.self::DOCUMENT_DETAILS.' AS det
                    LEFT JOIN '.self::DOCUMENT_TITLES.' AS tit
                        ON det.fld_document_title_id_ref = tit.fld_document_title_id
                    WHERE tit.fld_year_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                      AND det.fld_account_id_ref IN (
                          SELECT fld_account_id_ref
                          FROM '.self::SUBTABLES.'
                          WHERE fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                      )
                    ',
                    [$yearId, $accountId]
                );

                return (float) ($row->balance ?? 0);
            }

            $converted = DB::selectOne(
                '
                SELECT (
                    SUM(ISNULL(det.fld_document_detail_debit, 0) / NULLIF(det.fld_currency_rate, 0))
                    - SUM(ISNULL(det.fld_document_detail_credit, 0) / NULLIF(det.fld_currency_rate, 0))
                ) AS balance
                FROM '.self::DOCUMENT_DETAILS.' AS det
                LEFT JOIN '.self::DOCUMENT_TITLES.' AS tit
                    ON det.fld_document_title_id_ref = tit.fld_document_title_id
                WHERE tit.fld_year_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                  AND det.fld_currency_id_ref <> ?
                  AND det.fld_account_id_ref IN (
                      SELECT fld_account_id_ref
                      FROM '.self::SUBTABLES.'
                      WHERE fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                  )
                ',
                [$yearId, $currency, $accountId]
            );

            $balance = (float) ($converted->balance ?? 0);
            $rateRow = DB::selectOne(
                'SELECT CAST(fld_exchange_rate AS float) AS exchange_rate
                 FROM '.self::CURRENCIES.'
                 WHERE fld_currency_id = ?',
                [$currency]
            );
            $rate = (float) ($rateRow->exchange_rate ?? 0);
            if ($rate > 0) {
                $balance *= $rate;
            }

            $native = DB::selectOne(
                '
                SELECT (
                    SUM(ISNULL(det.fld_document_detail_debit, 0))
                    - SUM(ISNULL(det.fld_document_detail_credit, 0))
                ) AS balance
                FROM '.self::DOCUMENT_DETAILS.' AS det
                LEFT JOIN '.self::DOCUMENT_TITLES.' AS tit
                    ON det.fld_document_title_id_ref = tit.fld_document_title_id
                WHERE tit.fld_year_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                  AND det.fld_currency_id_ref = ?
                  AND det.fld_account_id_ref IN (
                      SELECT fld_account_id_ref
                      FROM '.self::SUBTABLES.'
                      WHERE fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                  )
                ',
                [$yearId, $currency, $accountId]
            );

            return $balance + (float) ($native->balance ?? 0);
        } catch (Throwable $e) {
            Log::warning('client_balance.set_based_failed', [
                'account_id' => $accountId,
                'message' => $e->getMessage(),
            ]);

            return 0.0;
        }
    }

    private function validatedSpName(): string
    {
        $sp = trim((string) config('reporting.client_balance_sp', 'dbo.SP_Get_Account_Balance'));
        if (! preg_match('/^dbo\.[A-Za-z_][A-Za-z0-9_]*$/', $sp)) {
            throw new RuntimeException('Invalid client balance stored procedure configuration.');
        }

        return $sp;
    }
}
