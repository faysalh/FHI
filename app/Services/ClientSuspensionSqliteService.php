<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

class ClientSuspensionSqliteService
{
    private const CONNECTION = 'client_suspensions_sqlite';

    private const TABLE = 'client_suspensions';

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    public function isSuspended(string $accountId): bool
    {
        $id = $this->normalize($accountId);
        if ($id === '') {
            return false;
        }

        $this->ensureReady();

        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id FROM '.self::TABLE.' WHERE client_account_id = ? LIMIT 1',
            [$id]
        );

        return $row !== null;
    }

    /**
     * @return array<string, true>
     */
    public function suspendedIdSet(): array
    {
        $this->ensureReady();
        $set = [];
        foreach (DB::connection(self::CONNECTION)->select(
            'SELECT client_account_id FROM '.self::TABLE
        ) as $row) {
            $id = $this->normalize((string) ($row->client_account_id ?? ''));
            if ($id !== '') {
                $set[$id] = true;
            }
        }

        return $set;
    }

    /**
     * @return list<stdClass>
     */
    public function list(string $search = ''): array
    {
        $this->ensureReady();
        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT id, client_account_id, client_code, client_name, salesman_name, suspended_by, suspended_at
             FROM '.self::TABLE.'
             ORDER BY client_name COLLATE NOCASE ASC, id ASC'
        );

        $search = mb_strtolower(trim($search));
        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static function (stdClass $row) use ($search): bool {
                $haystack = mb_strtolower(trim(
                    (string) ($row->client_name ?? '').' '
                    .(string) ($row->client_code ?? '').' '
                    .(string) ($row->salesman_name ?? '')
                ));

                return str_contains($haystack, $search);
            }
        ));
    }

    public function suspend(
        string $accountId,
        string $clientCode,
        string $clientName,
        string $salesmanName,
        string $suspendedBy,
    ): void {
        $id = $this->normalize($accountId);
        if ($id === '') {
            throw new InvalidArgumentException('Choose a client.');
        }

        $this->ensureReady();
        $now = now()->toDateTimeString();
        $existing = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id FROM '.self::TABLE.' WHERE client_account_id = ? LIMIT 1',
            [$id]
        );

        if ($existing !== null) {
            DB::connection(self::CONNECTION)->update(
                'UPDATE '.self::TABLE.'
                 SET client_code = ?, client_name = ?, salesman_name = ?, suspended_by = ?, suspended_at = ?, updated_at = ?
                 WHERE client_account_id = ?',
                [
                    trim($clientCode),
                    trim($clientName),
                    trim($salesmanName),
                    trim($suspendedBy),
                    $now,
                    $now,
                    $id,
                ]
            );

            return;
        }

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::TABLE.' (
                client_account_id, client_code, client_name, salesman_name, suspended_by, suspended_at, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                trim($clientCode),
                trim($clientName),
                trim($salesmanName),
                trim($suspendedBy),
                $now,
                $now,
                $now,
            ]
        );
    }

    public function release(string $accountId): bool
    {
        $id = $this->normalize($accountId);
        if ($id === '') {
            return false;
        }

        $this->ensureReady();

        return DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::TABLE.' WHERE client_account_id = ?',
            [$id]
        ) > 0;
    }

    private function normalize(string $value): string
    {
        $text = strtoupper(trim($value, "{} \t\n\r"));
        if ($text === '' || ! Str::isUuid($text)) {
            return '';
        }

        return $text;
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureDatabaseFileExists();
        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_account_id TEXT NOT NULL UNIQUE,
                client_code TEXT NOT NULL DEFAULT \'\',
                client_name TEXT NOT NULL DEFAULT \'\',
                salesman_name TEXT NOT NULL DEFAULT \'\',
                suspended_by TEXT NOT NULL DEFAULT \'\',
                suspended_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

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
        if ($directory !== '' && $directory !== '.' && ! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (! File::exists($path)) {
            File::put($path, '');
        }
    }
}
