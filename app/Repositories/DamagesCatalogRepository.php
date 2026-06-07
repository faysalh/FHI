<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Read-only lookups against the main reporting database (client names, etc.).
 */
class DamagesCatalogRepository
{
    /**
     * @return list<stdClass>
     */
    public function searchClients(string $q, int $limit = 40): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $pattern = '%'.$this->escapeLikePattern($q).'%';

        $sql = "
            SELECT TOP ({$limit})
                CAST(a.fld_account_id AS NVARCHAR(100)) AS account_id,
                LTRIM(RTRIM(CAST(COALESCE(a.fld_account_code, N'') AS NVARCHAR(200)))) AS client_code,
                LTRIM(RTRIM(CAST(COALESCE(a.fld_account_name, N'') AS NVARCHAR(500)))) AS client_name
            FROM dbo.tbl_accounting_accounts AS a
            WHERE a.fld_account_name LIKE ? ESCAPE N'\\'
               OR a.fld_account_code LIKE ? ESCAPE N'\\'
            ORDER BY a.fld_account_name ASC
        ";

        return DB::select($sql, [$pattern, $pattern]);
    }

    /**
     * Client’s assigned salesman from accounting accounts (read-only).
     */
    public function getSalesmanForClientAccount(string $accountId): ?stdClass
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return null;
        }

        $accountId = trim($accountId);
        if ($accountId === '') {
            return null;
        }

        $row = DB::selectOne(
            'SELECT TOP 1
                CAST(a.fld_sales_man_id_ref AS NVARCHAR(100)) AS salesman_id,
                LTRIM(RTRIM(CAST(COALESCE(sm.fld_account_name, N\'\') AS NVARCHAR(500)))) AS salesman_name
             FROM dbo.tbl_accounting_accounts AS a
             LEFT JOIN dbo.tbl_accounting_accounts AS sm
                ON sm.fld_account_id = a.fld_sales_man_id_ref
             WHERE CAST(a.fld_account_id AS NVARCHAR(100)) = ?',
            [$accountId]
        );

        if (! $row instanceof stdClass) {
            return null;
        }

        $sid = trim((string) ($row->salesman_id ?? ''));
        if ($sid === '') {
            return null;
        }

        return $row;
    }

    public function findClientById(string $accountId): ?stdClass
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return null;
        }

        $accountId = trim($accountId);
        if ($accountId === '') {
            return null;
        }

        $row = DB::selectOne(
            "SELECT TOP 1
                CAST(a.fld_account_id AS NVARCHAR(100)) AS account_id,
                LTRIM(RTRIM(CAST(COALESCE(a.fld_account_code, N'') AS NVARCHAR(200)))) AS client_code,
                LTRIM(RTRIM(CAST(COALESCE(a.fld_account_name, N'') AS NVARCHAR(500)))) AS client_name
             FROM dbo.tbl_accounting_accounts AS a
             WHERE CAST(a.fld_account_id AS NVARCHAR(100)) = ?",
            [$accountId]
        );

        return $row instanceof stdClass ? $row : null;
    }

    private function escapeLikePattern(string $value): string
    {
        $value = str_replace('[', '[[]', $value);

        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
