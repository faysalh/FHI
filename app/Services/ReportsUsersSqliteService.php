<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DeliveriesReportAccess;
use App\Support\StorageReportAccess;
use App\Support\ReportNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ReportsUsersSqliteService
{
    private const CONNECTION = 'reports_users_sqlite';

    private const USERS_TABLE = 'report_users';

    private const PERMISSIONS_TABLE = 'report_user_permissions';

    private const DELIVERIES_ACCESS_TABLE = 'report_user_deliveries_access';

    private const STORAGE_ACCESS_TABLE = 'report_user_storage_access';

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
        $this->bootstrapDefaultSuperAdminIfEmpty();
    }

    public function authenticate(string $username, string $password): ?object
    {
        $this->ensureReady();
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        $user = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, username, password_hash, is_super_admin, created_at, updated_at
             FROM '.self::USERS_TABLE.'
             WHERE lower(username) = lower(?)
             LIMIT 1',
            [$username]
        );

        if ($user === null || ! Hash::check($password, (string) ($user->password_hash ?? ''))) {
            return null;
        }

        return $user;
    }

    /**
     * @return list<object>
     */
    public function listUsers(): array
    {
        $this->ensureReady();

        $users = DB::connection(self::CONNECTION)->select(
            'SELECT id, username, is_super_admin, created_at, updated_at
             FROM '.self::USERS_TABLE.'
             ORDER BY lower(username) ASC, id ASC'
        );

        foreach ($users as $user) {
            $userId = (int) ($user->id ?? 0);
            $user->report_keys = $this->permissionKeysForUserId($userId);
            $user->deliveries_access = $this->deliveriesAccessForUserId($userId)->toArray();
            $user->storage_access = $this->storageAccessForUserId($userId)->toArray();
        }

        return $users;
    }

    public function findUserById(int $userId): ?object
    {
        $this->ensureReady();
        if ($userId <= 0) {
            return null;
        }

        $user = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, username, is_super_admin, created_at, updated_at
             FROM '.self::USERS_TABLE.'
             WHERE id = ?
             LIMIT 1',
            [$userId]
        );

        if ($user === null) {
            return null;
        }

        $user->report_keys = $this->permissionKeysForUserId($userId);
        $user->deliveries_access = $this->deliveriesAccessForUserId($userId)->toArray();
        $user->storage_access = $this->storageAccessForUserId($userId)->toArray();

        return $user;
    }

    public function deliveriesAccessForUserId(int $userId): DeliveriesReportAccess
    {
        $this->ensureReady();
        if ($userId <= 0) {
            return DeliveriesReportAccess::full();
        }

        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT can_filter_date, can_filter_city, can_filter_storage, can_filter_salesman,
                    can_filter_status, can_edit_status, default_storage
             FROM '.self::DELIVERIES_ACCESS_TABLE.'
             WHERE user_id = ?
             LIMIT 1',
            [$userId]
        );

        if ($row === null) {
            return DeliveriesReportAccess::full();
        }

        return DeliveriesReportAccess::fromArray([
            'can_filter_date' => (int) ($row->can_filter_date ?? 1) === 1,
            'can_filter_city' => (int) ($row->can_filter_city ?? 1) === 1,
            'can_filter_storage' => (int) ($row->can_filter_storage ?? 1) === 1,
            'can_filter_salesman' => (int) ($row->can_filter_salesman ?? 1) === 1,
            'can_filter_status' => (int) ($row->can_filter_status ?? 1) === 1,
            'can_edit_status' => (int) ($row->can_edit_status ?? 1) === 1,
            'default_storage' => $row->default_storage ?? null,
        ]);
    }

    public function syncDeliveriesAccess(int $userId, DeliveriesReportAccess $access): void
    {
        $this->ensureReady();
        if ($userId <= 0) {
            return;
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::DELIVERIES_ACCESS_TABLE.' WHERE user_id = ?',
            [$userId]
        );

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::DELIVERIES_ACCESS_TABLE.' (
                user_id, can_filter_date, can_filter_city, can_filter_storage, can_filter_salesman,
                can_filter_status, can_edit_status, default_storage
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $access->canFilterDate ? 1 : 0,
                $access->canFilterCity ? 1 : 0,
                $access->canFilterStorage ? 1 : 0,
                $access->canFilterSalesman ? 1 : 0,
                $access->canFilterStatus ? 1 : 0,
                $access->canEditStatus ? 1 : 0,
                $access->defaultStorage,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function deliveriesAccessFromInput(array $input): DeliveriesReportAccess
    {
        $storage = isset($input['deliveries_default_storage'])
            ? trim((string) $input['deliveries_default_storage'])
            : '';

        return new DeliveriesReportAccess(
            canFilterDate: (bool) ($input['deliveries_can_filter_date'] ?? false),
            canFilterCity: (bool) ($input['deliveries_can_filter_city'] ?? false),
            canFilterStorage: (bool) ($input['deliveries_can_filter_storage'] ?? false),
            canFilterSalesman: (bool) ($input['deliveries_can_filter_salesman'] ?? false),
            canFilterStatus: (bool) ($input['deliveries_can_filter_status'] ?? false),
            canEditStatus: (bool) ($input['deliveries_can_edit_status'] ?? false),
            defaultStorage: $storage !== '' ? $storage : null,
        );
    }

    public function storageAccessForUserId(int $userId): StorageReportAccess
    {
        $this->ensureReady();
        if ($userId <= 0) {
            return StorageReportAccess::full();
        }

        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT can_filter_storage, allowed_storages
             FROM '.self::STORAGE_ACCESS_TABLE.'
             WHERE user_id = ?
             LIMIT 1',
            [$userId]
        );

        if ($row === null) {
            return StorageReportAccess::full();
        }

        return StorageReportAccess::fromArray([
            'can_filter_storage' => (int) ($row->can_filter_storage ?? 1) === 1,
            'allowed_storages' => $row->allowed_storages ?? '[]',
        ]);
    }

    public function syncStorageAccess(int $userId, StorageReportAccess $access): void
    {
        $this->ensureReady();
        if ($userId <= 0) {
            return;
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::STORAGE_ACCESS_TABLE.' WHERE user_id = ?',
            [$userId]
        );

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::STORAGE_ACCESS_TABLE.' (user_id, can_filter_storage, allowed_storages)
             VALUES (?, ?, ?)',
            [
                $userId,
                $access->canFilterStorage ? 1 : 0,
                json_encode($access->allowedStorages, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function storageAccessFromInput(array $input): StorageReportAccess
    {
        $raw = $input['storage_allowed_storages'] ?? [];
        if (! is_array($raw)) {
            $raw = $raw !== null && $raw !== '' ? [(string) $raw] : [];
        }
        $allowed = [];
        foreach ($raw as $value) {
            $name = trim((string) $value);
            if ($name !== '') {
                $allowed[] = $name;
            }
        }

        return new StorageReportAccess(
            canFilterStorage: (bool) ($input['storage_can_filter_storage'] ?? false),
            allowedStorages: array_values(array_unique($allowed)),
        );
    }

    /**
     * @param  list<string>  $reportKeys
     */
    public function createUser(
        string $username,
        string $password,
        bool $isSuperAdmin,
        array $reportKeys,
        ?DeliveriesReportAccess $deliveriesAccess = null,
        ?StorageReportAccess $storageAccess = null,
    ): int {
        $this->ensureReady();
        $username = $this->normalizeUsername($username);
        if ($username === '') {
            throw new RuntimeException('Username is required.');
        }
        if (trim($password) === '') {
            throw new RuntimeException('Password is required.');
        }

        if ($this->usernameExists($username)) {
            throw new RuntimeException('Username is already taken.');
        }

        $now = now()->toDateTimeString();
        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::USERS_TABLE.' (username, password_hash, is_super_admin, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$username, Hash::make($password), $isSuperAdmin ? 1 : 0, $now, $now]
        );

        $userId = (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
        if (! $isSuperAdmin) {
            $this->syncPermissions($userId, $reportKeys);
            if ($deliveriesAccess !== null && in_array('deliveries', $reportKeys, true)) {
                $this->syncDeliveriesAccess($userId, $deliveriesAccess);
            }
            if ($storageAccess !== null && in_array('storage', $reportKeys, true)) {
                $this->syncStorageAccess($userId, $storageAccess);
            }
        }

        return $userId;
    }

    /**
     * @param  list<string>  $reportKeys
     */
    public function updateUser(
        int $userId,
        bool $isSuperAdmin,
        array $reportKeys,
        ?string $newPassword = null,
        ?DeliveriesReportAccess $deliveriesAccess = null,
        ?StorageReportAccess $storageAccess = null,
    ): void {
        $this->ensureReady();
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user.');
        }

        $user = $this->findUserById($userId);
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        $now = now()->toDateTimeString();
        if ($newPassword !== null && trim($newPassword) !== '') {
            DB::connection(self::CONNECTION)->update(
                'UPDATE '.self::USERS_TABLE.'
                 SET is_super_admin = ?, password_hash = ?, updated_at = ?
                 WHERE id = ?',
                [$isSuperAdmin ? 1 : 0, Hash::make($newPassword), $now, $userId]
            );
        } else {
            DB::connection(self::CONNECTION)->update(
                'UPDATE '.self::USERS_TABLE.'
                 SET is_super_admin = ?, updated_at = ?
                 WHERE id = ?',
                [$isSuperAdmin ? 1 : 0, $now, $userId]
            );
        }

        if ($isSuperAdmin) {
            DB::connection(self::CONNECTION)->delete(
                'DELETE FROM '.self::PERMISSIONS_TABLE.' WHERE user_id = ?',
                [$userId]
            );
            DB::connection(self::CONNECTION)->delete(
                'DELETE FROM '.self::DELIVERIES_ACCESS_TABLE.' WHERE user_id = ?',
                [$userId]
            );
            DB::connection(self::CONNECTION)->delete(
                'DELETE FROM '.self::STORAGE_ACCESS_TABLE.' WHERE user_id = ?',
                [$userId]
            );
        } else {
            $this->syncPermissions($userId, $reportKeys);
            if (in_array('deliveries', $reportKeys, true) && $deliveriesAccess !== null) {
                $this->syncDeliveriesAccess($userId, $deliveriesAccess);
            } else {
                DB::connection(self::CONNECTION)->delete(
                    'DELETE FROM '.self::DELIVERIES_ACCESS_TABLE.' WHERE user_id = ?',
                    [$userId]
                );
            }
            if (in_array('storage', $reportKeys, true) && $storageAccess !== null) {
                $this->syncStorageAccess($userId, $storageAccess);
            } else {
                DB::connection(self::CONNECTION)->delete(
                    'DELETE FROM '.self::STORAGE_ACCESS_TABLE.' WHERE user_id = ?',
                    [$userId]
                );
            }
        }
    }

    public function deleteUser(int $userId): void
    {
        $this->ensureReady();
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user.');
        }

        $superAdminCount = (int) (DB::connection(self::CONNECTION)->selectOne(
            'SELECT COUNT(*) AS c FROM '.self::USERS_TABLE.' WHERE is_super_admin = 1'
        )->c ?? 0);

        $user = $this->findUserById($userId);
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        if ((int) ($user->is_super_admin ?? 0) === 1 && $superAdminCount <= 1) {
            throw new RuntimeException('Cannot delete the last administrator account.');
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::USERS_TABLE.' WHERE id = ?',
            [$userId]
        );
    }

    /**
     * @return list<string>
     */
    public function permissionKeysForUserId(int $userId): array
    {
        $this->ensureReady();
        if ($userId <= 0) {
            return [];
        }

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT report_key FROM '.self::PERMISSIONS_TABLE.' WHERE user_id = ? ORDER BY report_key ASC',
            [$userId]
        );

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->report_key ?? ''),
            $rows
        ));
    }

    public function countUsers(): int
    {
        $this->ensureSchema();

        return (int) (DB::connection(self::CONNECTION)->selectOne(
            'SELECT COUNT(*) AS c FROM '.self::USERS_TABLE
        )->c ?? 0);
    }

    /**
     * @param  list<string>  $reportKeys
     */
    private function syncPermissions(int $userId, array $reportKeys): void
    {
        $allowed = array_flip(ReportNavigation::allReportKeys());
        $normalized = [];
        foreach ($reportKeys as $key) {
            if (! is_string($key)) {
                continue;
            }
            $key = trim($key);
            if ($key !== '' && isset($allowed[$key])) {
                $normalized[$key] = $key;
            }
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::PERMISSIONS_TABLE.' WHERE user_id = ?',
            [$userId]
        );

        foreach ($normalized as $key) {
            DB::connection(self::CONNECTION)->insert(
                'INSERT INTO '.self::PERMISSIONS_TABLE.' (user_id, report_key) VALUES (?, ?)',
                [$userId, $key]
            );
        }
    }

    private function usernameExists(string $username): bool
    {
        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id FROM '.self::USERS_TABLE.' WHERE lower(username) = lower(?) LIMIT 1',
            [$username]
        );

        return $row !== null;
    }

    private function normalizeUsername(string $username): string
    {
        return trim($username);
    }

    private function bootstrapDefaultSuperAdminIfEmpty(): void
    {
        if ($this->countUsers() > 0) {
            return;
        }

        $username = trim((string) config('reporting.bootstrap_admin.username', 'admin'));
        $password = (string) config('reporting.bootstrap_admin.password', '');
        if ($username === '' || $password === '') {
            return;
        }

        $now = now()->toDateTimeString();
        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::USERS_TABLE.' (username, password_hash, is_super_admin, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?)',
            [$username, Hash::make($password), $now, $now]
        );
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureDatabaseFileExists();
        $db = DB::connection(self::CONNECTION);
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::USERS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL COLLATE NOCASE UNIQUE,
                password_hash TEXT NOT NULL,
                is_super_admin INTEGER NOT NULL DEFAULT 0 CHECK (is_super_admin IN (0, 1)),
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::PERMISSIONS_TABLE.' (
                user_id INTEGER NOT NULL,
                report_key TEXT NOT NULL,
                PRIMARY KEY (user_id, report_key),
                FOREIGN KEY (user_id) REFERENCES '.self::USERS_TABLE.'(id) ON DELETE CASCADE
            )'
        );
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_report_user_permissions_key ON '.self::PERMISSIONS_TABLE.' (report_key)'
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::DELIVERIES_ACCESS_TABLE.' (
                user_id INTEGER PRIMARY KEY,
                can_filter_date INTEGER NOT NULL DEFAULT 1 CHECK (can_filter_date IN (0, 1)),
                can_filter_city INTEGER NOT NULL DEFAULT 1 CHECK (can_filter_city IN (0, 1)),
                can_filter_storage INTEGER NOT NULL DEFAULT 1 CHECK (can_filter_storage IN (0, 1)),
                can_filter_salesman INTEGER NOT NULL DEFAULT 1 CHECK (can_filter_salesman IN (0, 1)),
                can_filter_status INTEGER NOT NULL DEFAULT 1 CHECK (can_filter_status IN (0, 1)),
                can_edit_status INTEGER NOT NULL DEFAULT 1 CHECK (can_edit_status IN (0, 1)),
                default_storage TEXT,
                FOREIGN KEY (user_id) REFERENCES '.self::USERS_TABLE.'(id) ON DELETE CASCADE
            )'
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::STORAGE_ACCESS_TABLE.' (
                user_id INTEGER PRIMARY KEY,
                can_filter_storage INTEGER NOT NULL DEFAULT 1 CHECK (can_filter_storage IN (0, 1)),
                allowed_storages TEXT NOT NULL DEFAULT "[]",
                FOREIGN KEY (user_id) REFERENCES '.self::USERS_TABLE.'(id) ON DELETE CASCADE
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
        if ($directory !== '' && $directory !== '.' && ! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (! File::exists($path)) {
            File::put($path, '');
        }
    }
}
