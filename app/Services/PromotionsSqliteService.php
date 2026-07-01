<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PromotionsWeekdays;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use stdClass;

class PromotionsSqliteService
{
    private const CONNECTION = 'promotions_sqlite';

    private const PROMOTERS_TABLE = 'promotion_promoters';

    private const ASSIGNMENTS_TABLE = 'promotion_client_assignments';

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    /**
     * @return list<object>
     */
    public function listPromoters(): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, employee_name, vehicle, default_visit_days, created_at, updated_at
             FROM '.self::PROMOTERS_TABLE.'
             ORDER BY employee_name COLLATE NOCASE ASC, id ASC'
        );
    }

    public function getPromoter(int $id): ?object
    {
        $this->ensureReady();
        if ($id < 1) {
            return null;
        }

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, employee_name, vehicle, default_visit_days, created_at, updated_at
             FROM '.self::PROMOTERS_TABLE.'
             WHERE id = ?
             LIMIT 1',
            [$id]
        );
    }

    /**
     * @param  list<int>  $defaultVisitDays
     */
    public function createPromoter(string $employeeName, string $vehicle, array $defaultVisitDays = []): int
    {
        $this->ensureReady();
        $now = now()->toDateTimeString();
        $employeeName = trim($employeeName);
        if ($employeeName === '') {
            throw new \InvalidArgumentException('Employee name is required.');
        }

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::PROMOTERS_TABLE.' (employee_name, vehicle, default_visit_days, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [
                $employeeName,
                trim($vehicle) !== '' ? trim($vehicle) : null,
                PromotionsWeekdays::toJson([]),
                $now,
                $now,
            ]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function updatePromoter(int $id, string $employeeName, string $vehicle, array $defaultVisitDays = []): void
    {
        $this->ensureReady();
        $this->findPromoterOrFail($id);
        $employeeName = trim($employeeName);
        if ($employeeName === '') {
            throw new \InvalidArgumentException('Employee name is required.');
        }

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::PROMOTERS_TABLE.'
             SET employee_name = ?, vehicle = ?, default_visit_days = ?, updated_at = ?
             WHERE id = ?',
            [
                $employeeName,
                trim($vehicle) !== '' ? trim($vehicle) : null,
                PromotionsWeekdays::toJson([]),
                now()->toDateTimeString(),
                $id,
            ]
        );
    }

    public function deletePromoter(int $id): void
    {
        $this->ensureReady();
        $this->findPromoterOrFail($id);

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::ASSIGNMENTS_TABLE.' WHERE promoter_id = ?',
            [$id]
        );
        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::PROMOTERS_TABLE.' WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return list<object>
     */
    public function listAssignmentsForPromoter(int $promoterId): array
    {
        $this->ensureReady();
        if ($promoterId < 1) {
            return [];
        }

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, promoter_id, client_account_id, client_name, visit_days_override, created_at, updated_at
             FROM '.self::ASSIGNMENTS_TABLE.'
             WHERE promoter_id = ?
             ORDER BY client_name COLLATE NOCASE ASC, id ASC',
            [$promoterId]
        );
    }

    /**
     * @param  list<int>  $visitDays
     */
    public function assignClient(
        int $promoterId,
        string $clientAccountId,
        string $clientName,
        array $visitDays
    ): void {
        $this->ensureReady();
        $this->findPromoterOrFail($promoterId);
        $clientAccountId = trim($clientAccountId);
        $clientName = trim($clientName);
        if ($clientAccountId === '' || $clientName === '') {
            throw new \InvalidArgumentException('Client is required.');
        }

        $dailyVisits = PromotionsWeekdays::isDailyVisitSchedule($visitDays);
        PromotionsWeekdays::validateVisitDays($visitDays, $dailyVisits);
        $overrideJson = PromotionsWeekdays::toJson($visitDays);

        $existing = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id FROM '.self::ASSIGNMENTS_TABLE.'
             WHERE client_account_id = ? AND promoter_id != ?
             LIMIT 1',
            [$clientAccountId, $promoterId]
        );
        if ($existing !== null) {
            throw new \InvalidArgumentException('This client is already assigned to another promoter.');
        }

        $now = now()->toDateTimeString();
        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id FROM '.self::ASSIGNMENTS_TABLE.'
             WHERE promoter_id = ? AND client_account_id = ?
             LIMIT 1',
            [$promoterId, $clientAccountId]
        );

        if ($row !== null) {
            DB::connection(self::CONNECTION)->update(
                'UPDATE '.self::ASSIGNMENTS_TABLE.'
                 SET client_name = ?, visit_days_override = ?, updated_at = ?
                 WHERE id = ?',
                [$clientName, $overrideJson, $now, (int) $row->id]
            );

            return;
        }

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::ASSIGNMENTS_TABLE.' (
                promoter_id, client_account_id, client_name, visit_days_override, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?)',
            [$promoterId, $clientAccountId, $clientName, $overrideJson, $now, $now]
        );
    }

    /**
     * @param  list<int>  $visitDays
     */
    public function updateAssignment(int $assignmentId, array $visitDays): void
    {
        $this->ensureReady();
        $this->findAssignmentOrFail($assignmentId);

        $dailyVisits = PromotionsWeekdays::isDailyVisitSchedule($visitDays);
        PromotionsWeekdays::validateVisitDays($visitDays, $dailyVisits);

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::ASSIGNMENTS_TABLE.'
             SET visit_days_override = ?, updated_at = ?
             WHERE id = ?',
            [PromotionsWeekdays::toJson($visitDays), now()->toDateTimeString(), $assignmentId]
        );
    }

    public function deleteAssignment(int $assignmentId): void
    {
        $this->ensureReady();
        $this->findAssignmentOrFail($assignmentId);

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::ASSIGNMENTS_TABLE.' WHERE id = ?',
            [$assignmentId]
        );
    }

    /**
     * @return list<int>
     */
    public function effectiveVisitDays(object $promoter, object $assignment): array
    {
        return PromotionsWeekdays::parseCsv((string) ($assignment->visit_days_override ?? '[]'));
    }

    private function findPromoterOrFail(int $id): object
    {
        $row = $this->getPromoter($id);
        if ($row === null) {
            throw new \InvalidArgumentException('Promoter not found.');
        }

        return $row;
    }

    private function findAssignmentOrFail(int $id): object
    {
        $this->ensureReady();
        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, promoter_id, client_account_id, client_name, visit_days_override, created_at, updated_at
             FROM '.self::ASSIGNMENTS_TABLE.'
             WHERE id = ?
             LIMIT 1',
            [$id]
        );
        if ($row === null) {
            throw new \InvalidArgumentException('Client assignment not found.');
        }

        return $row;
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureDatabaseFileExists();
        $db = DB::connection(self::CONNECTION);

        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::PROMOTERS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_name TEXT NOT NULL,
                vehicle TEXT,
                default_visit_days TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::ASSIGNMENTS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                promoter_id INTEGER NOT NULL,
                client_account_id TEXT NOT NULL,
                client_name TEXT NOT NULL,
                visit_days_override TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (promoter_id) REFERENCES '.self::PROMOTERS_TABLE.'(id) ON DELETE CASCADE,
                UNIQUE (client_account_id)
            )'
        );

        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_promotion_assignments_promoter
             ON '.self::ASSIGNMENTS_TABLE.' (promoter_id)'
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
