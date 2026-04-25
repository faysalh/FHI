<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class IdentifierRepository
{
    private const ACCOUNTING_ACCOUNTS = 'dbo.tbl_accounting_accounts';

    /** Parent account uniqueidentifier: rows under this node are treated as salesmen. */
    public const SALESMAN_PARENT_ACCOUNT_GUID = '2D2A670B-5346-400F-8A53-C4AB44D4C9D8';

    /**
     * Distinct item description values (chicken category labels) from dbo.tbl_store_items.
     *
     * @return list<list<string>>
     */
    public function fetchItemCategoryDescriptionSamples(int $limit = 5): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $table = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $desc = $this->bracketIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        try {
            $rows = DB::select(
                '
                SELECT DISTINCT TOP ('.$limit.')
                    LTRIM(RTRIM(CAST(i.'.$desc.' AS NVARCHAR(500)))) AS description_text
                FROM '.$table.' AS i
                WHERE i.'.$desc.' IS NOT NULL
                  AND LTRIM(RTRIM(CAST(i.'.$desc.' AS NVARCHAR(500)))) <> N\'\'
                ORDER BY description_text
                '
            );
        } catch (Throwable $e) {
            Log::warning('identifier.item_category_samples_failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $txt = $this->cell($r->description_text ?? null);
            $out[] = [
                $table,
                trim($desc, '[]'),
                $txt,
                'Join: tbl_store_document_detail.fld_item_id_ref = tbl_store_items.'.trim($desc, '[]'),
                'Used for sales breakdown by category',
            ];
        }

        return $out;
    }

    private function bracketIdentifier(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }

    /**
     * Up to five salesman rows: fld_account_name where fld_parent_account_id_ref matches {@see SALESMAN_PARENT_ACCOUNT_GUID}.
     *
     * @return list<list<string>>
     */
    public function fetchSalesmanIdentifierSamples(int $limit = 5): array
    {
        $limit = max(1, min(5, $limit));

        try {
            $rows = DB::select(
                '
                SELECT TOP ('.$limit.')
                    CAST(a.fld_account_name AS NVARCHAR(500)) AS account_name,
                    CONVERT(VARCHAR(36), a.fld_parent_account_id_ref) AS parent_ref,
                    CAST(a.fld_account_id AS NVARCHAR(50)) AS account_id
                FROM '.self::ACCOUNTING_ACCOUNTS.' AS a
                WHERE a.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                ORDER BY a.fld_account_id
                ',
                [self::SALESMAN_PARENT_ACCOUNT_GUID]
            );
        } catch (Throwable $e) {
            Log::warning('identifier.salesman_samples_failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                self::ACCOUNTING_ACCOUNTS,
                $this->cell($r->account_name ?? null),
                $this->cell($r->parent_ref ?? null),
                $this->cell($r->account_id ?? null),
                'Salesman (parent matches rule)',
            ];
        }

        return $out;
    }

    /**
     * Up to five client rows: accounts whose fld_sales_man_id_ref equals a salesman’s fld_account_id
     * (salesmen are rows in the same table with fld_parent_account_id_ref = {@see SALESMAN_PARENT_ACCOUNT_GUID}).
     *
     * @return list<list<string>>
     */
    public function fetchClientIdentifierSamples(int $limit = 5): array
    {
        $limit = max(1, min(5, $limit));

        try {
            $rows = DB::select(
                '
                SELECT TOP ('.$limit.')
                    CAST(COALESCE(c.fld_account_code, N\'\') AS NVARCHAR(120)) AS client_code,
                    CAST(COALESCE(c.fld_account_name, N\'\') AS NVARCHAR(500)) AS client_name,
                    CAST(c.fld_account_id AS NVARCHAR(50)) AS client_account_id,
                    CAST(c.fld_sales_man_id_ref AS NVARCHAR(50)) AS sales_man_id_ref,
                    CAST(COALESCE(s.fld_account_name, N\'\') AS NVARCHAR(500)) AS salesman_name
                FROM '.self::ACCOUNTING_ACCOUNTS.' AS c
                INNER JOIN '.self::ACCOUNTING_ACCOUNTS.' AS s
                    ON s.fld_account_id = c.fld_sales_man_id_ref
                    AND s.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                ORDER BY c.fld_account_id
                ',
                [self::SALESMAN_PARENT_ACCOUNT_GUID]
            );
        } catch (Throwable $e) {
            Log::warning('identifier.client_samples_failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $this->cell($r->client_code ?? null),
                $this->cell($r->client_name ?? null),
                $this->cell($r->client_account_id ?? null),
                $this->cell($r->sales_man_id_ref ?? null),
                $this->cell($r->salesman_name ?? null),
            ];
        }

        return $out;
    }

    /**
     * Sample distinct city values from fld_city for client accounts (same join as clients).
     *
     * @return list<list<string>>
     */
    public function fetchCityIdentifierSamples(int $limit = 5): array
    {
        $limit = max(1, min(5, $limit));

        try {
            $rows = DB::select(
                '
                SELECT DISTINCT TOP ('.$limit.')
                    LTRIM(RTRIM(CAST(c.fld_city AS NVARCHAR(500)))) AS city_name
                FROM '.self::ACCOUNTING_ACCOUNTS.' AS c
                INNER JOIN '.self::ACCOUNTING_ACCOUNTS.' AS s
                    ON s.fld_account_id = c.fld_sales_man_id_ref
                    AND s.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                WHERE c.fld_city IS NOT NULL
                  AND LTRIM(RTRIM(CAST(c.fld_city AS NVARCHAR(500)))) <> N\'\'
                ORDER BY city_name
                ',
                [self::SALESMAN_PARENT_ACCOUNT_GUID]
            );
        } catch (Throwable $e) {
            Log::warning('identifier.city_samples_failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $name = $this->cell($r->city_name ?? null);
            $out[] = [
                self::ACCOUNTING_ACCOUNTS,
                'fld_city',
                $name,
                '',
                'Distinct value (client accounts)',
            ];
        }

        return $out;
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_string($value) ? $value : (string) $value;
    }
}
