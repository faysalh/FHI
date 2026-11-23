<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PdaLocationSqliteService
{
    private const CONNECTION = 'pda_locations_sqlite';

    private const TABLE = 'pda_voucher_locations';

    private bool $schemaChecked = false;

    public function record(
        string $voucherId,
        string $salesmanUserId,
        string $salesmanAccountId,
        string $salesmanName,
        string $customerId,
        string $customerName,
        string $voucherType,
        float $latitude,
        float $longitude,
        ?float $accuracy,
    ): void {
        $this->ensureReady();
        $now = now()->toDateTimeString();

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::TABLE.' (
                voucher_id, salesman_user_id, salesman_account_id, salesman_name,
                customer_id, customer_name, voucher_type, latitude, longitude, accuracy, captured_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                strtoupper(trim($voucherId)),
                strtoupper(trim($salesmanUserId)),
                strtoupper(trim($salesmanAccountId)),
                trim($salesmanName),
                strtoupper(trim($customerId)),
                trim($customerName),
                trim($voucherType),
                $latitude,
                $longitude,
                $accuracy,
                $now,
            ]
        );
    }

    /**
     * @return list<object>
     */
    public function recent(?string $salesmanUserId, int $limit = 50): array
    {
        $this->ensureReady();
        $limit = max(1, min(200, $limit));
        $salesmanUserId = strtoupper(trim((string) $salesmanUserId));

        if ($salesmanUserId === '') {
            return DB::connection(self::CONNECTION)->select(
                'SELECT id, voucher_id, salesman_user_id, salesman_name, customer_id, customer_name,
                        voucher_type, latitude, longitude, accuracy, captured_at
                 FROM '.self::TABLE.'
                 ORDER BY id DESC
                 LIMIT '.$limit
            );
        }

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, voucher_id, salesman_user_id, salesman_name, customer_id, customer_name,
                    voucher_type, latitude, longitude, accuracy, captured_at
             FROM '.self::TABLE.'
             WHERE salesman_user_id = ?
             ORDER BY id DESC
             LIMIT '.$limit,
            [$salesmanUserId]
        );
    }

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureDatabaseFileExists();
        $db = DB::connection(self::CONNECTION);
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                voucher_id TEXT NOT NULL,
                salesman_user_id TEXT NOT NULL,
                salesman_account_id TEXT NOT NULL,
                salesman_name TEXT NOT NULL,
                customer_id TEXT NOT NULL,
                customer_name TEXT NOT NULL,
                voucher_type TEXT NOT NULL,
                latitude REAL NOT NULL,
                longitude REAL NOT NULL,
                accuracy REAL NULL,
                captured_at TEXT NOT NULL
            )'
        );
        $db->statement('CREATE INDEX IF NOT EXISTS idx_pda_locations_salesman ON '.self::TABLE.' (salesman_user_id, id)');
        $db->statement('CREATE INDEX IF NOT EXISTS idx_pda_locations_voucher ON '.self::TABLE.' (voucher_id)');

        $this->schemaChecked = true;
    }

    private function ensureDatabaseFileExists(): void
    {
        /** @var mixed $configuredPath */
        $configuredPath = config('database.connections.'.self::CONNECTION.'.database');
        $path = trim((string) $configuredPath);
        if ($path === '' || $path === ':memory:') {
            return;
        }

        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && ! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (! File::exists($path)) {
            File::put($path, '');
        }
    }
}
