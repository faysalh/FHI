<?php

declare(strict_types=1);

namespace App\Services;

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
            $user->report_keys = $this->permissionKeysForUserId((int) ($user->id ?? 0));
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

        return $user;
    }

    /**
     * @param  list<string>  $reportKeys
     */
    public function createUser(string $username, string $password, bool $isSuperAdmin, array $reportKeys): int
    {
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
        }

        return $userId;
    }

    /**
     * @param  list<string>  $reportKeys
     */
    public function updateUser(int $userId, bool $isSuperAdmin, array $reportKeys, ?string $newPassword = null): void
    {
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
        } else {
            $this->syncPermissions($userId, $reportKeys);
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
