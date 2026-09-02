<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class FaceIdSqliteService
{
    public const DESCRIPTOR_LENGTH = 128;

    public const DEFAULT_MATCH_THRESHOLD = 0.55;

    private const CONNECTION = 'face_id_sqlite';

    private const SETTINGS_TABLE = 'face_id_settings';

    private const EMPLOYEES_TABLE = 'face_id_employees';

    private const ATTENDANCE_TABLE = 'face_id_attendance';

    private const DEBOUNCE_SECONDS = 60;

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    public function getKioskToken(): string
    {
        $this->ensureReady();

        $settings = $this->settingsRow();
        if ($settings === null || trim((string) ($settings->kiosk_token ?? '')) === '') {
            return $this->regenerateKioskToken();
        }

        return (string) $settings->kiosk_token;
    }

    public function regenerateKioskToken(): string
    {
        $this->ensureReady();

        $token = Str::random(64);
        $now = now()->toDateTimeString();

        $existing = $this->settingsRow();
        if ($existing === null) {
            DB::connection(self::CONNECTION)->insert(
                'INSERT INTO '.self::SETTINGS_TABLE.' (id, kiosk_token, match_threshold, created_at, updated_at)
                 VALUES (1, ?, ?, ?, ?)',
                [$token, self::DEFAULT_MATCH_THRESHOLD, $now, $now]
            );
        } else {
            DB::connection(self::CONNECTION)->update(
                'UPDATE '.self::SETTINGS_TABLE.'
                 SET kiosk_token = ?, updated_at = ?
                 WHERE id = 1',
                [$token, $now]
            );
        }

        return $token;
    }

    public function isValidKioskToken(string $token): bool
    {
        if ($token === '' || strlen($token) < 32) {
            return false;
        }

        $this->ensureReady();

        $settings = $this->settingsRow();
        if ($settings === null) {
            return false;
        }

        return hash_equals((string) $settings->kiosk_token, $token);
    }

    public function getMatchThreshold(): float
    {
        $this->ensureReady();

        $settings = $this->settingsRow();
        if ($settings === null) {
            return self::DEFAULT_MATCH_THRESHOLD;
        }

        $threshold = (float) ($settings->match_threshold ?? self::DEFAULT_MATCH_THRESHOLD);

        return $threshold > 0 ? $threshold : self::DEFAULT_MATCH_THRESHOLD;
    }

    /**
     * @return list<object>
     */
    public function listEmployees(): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, name, employee_code, is_active, face_descriptor, created_at, updated_at
             FROM '.self::EMPLOYEES_TABLE.'
             ORDER BY name COLLATE NOCASE ASC, id ASC'
        );
    }

    public function findEmployee(int $id): ?object
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, name, employee_code, is_active, face_descriptor, created_at, updated_at
             FROM '.self::EMPLOYEES_TABLE.'
             WHERE id = ?
             LIMIT 1',
            [$id]
        );
    }

    public function createEmployee(string $name, ?string $employeeCode = null): int
    {
        $this->ensureReady();
        $name = trim($name);
        $employeeCode = $this->normalizeEmployeeCode($employeeCode);

        if ($name === '') {
            throw new InvalidArgumentException('Employee name is required.');
        }

        $now = now()->toDateTimeString();
        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::EMPLOYEES_TABLE.' (name, employee_code, is_active, face_descriptor, created_at, updated_at)
             VALUES (?, ?, 1, NULL, ?, ?)',
            [$name, $employeeCode, $now, $now]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function updateEmployee(int $id, string $name, ?string $employeeCode, bool $isActive): void
    {
        $this->ensureReady();
        $name = trim($name);
        $employeeCode = $this->normalizeEmployeeCode($employeeCode);

        if ($name === '') {
            throw new InvalidArgumentException('Employee name is required.');
        }

        if ($this->findEmployee($id) === null) {
            throw new InvalidArgumentException('Employee not found.');
        }

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::EMPLOYEES_TABLE.'
             SET name = ?, employee_code = ?, is_active = ?, updated_at = ?
             WHERE id = ?',
            [$name, $employeeCode, $isActive ? 1 : 0, now()->toDateTimeString(), $id]
        );
    }

    public function deleteEmployee(int $id): void
    {
        $this->ensureReady();

        if ($this->findEmployee($id) === null) {
            throw new InvalidArgumentException('Employee not found.');
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::ATTENDANCE_TABLE.' WHERE employee_id = ?',
            [$id]
        );
        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::EMPLOYEES_TABLE.' WHERE id = ?',
            [$id]
        );
    }

    /**
     * @param  list<float|int>  $descriptor
     */
    public function saveFaceDescriptor(int $id, array $descriptor): void
    {
        $this->ensureReady();
        $this->assertValidDescriptor($descriptor);

        if ($this->findEmployee($id) === null) {
            throw new InvalidArgumentException('Employee not found.');
        }

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::EMPLOYEES_TABLE.'
             SET face_descriptor = ?, updated_at = ?
             WHERE id = ?',
            [json_encode(array_map('floatval', $descriptor), JSON_THROW_ON_ERROR), now()->toDateTimeString(), $id]
        );
    }

    public function clearFaceDescriptor(int $id): void
    {
        $this->ensureReady();

        if ($this->findEmployee($id) === null) {
            throw new InvalidArgumentException('Employee not found.');
        }

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::EMPLOYEES_TABLE.'
             SET face_descriptor = NULL, updated_at = ?
             WHERE id = ?',
            [now()->toDateTimeString(), $id]
        );
    }

    /**
     * @param  list<float|int>  $descriptor
     * @return array{employee: object, distance: float, confidence: float}|null
     */
    public function matchDescriptor(array $descriptor): ?array
    {
        $this->ensureReady();
        $this->assertValidDescriptor($descriptor);

        $threshold = $this->getMatchThreshold();
        $employees = DB::connection(self::CONNECTION)->select(
            'SELECT id, name, employee_code, face_descriptor
             FROM '.self::EMPLOYEES_TABLE.'
             WHERE is_active = 1 AND face_descriptor IS NOT NULL AND face_descriptor != \'\''
        );

        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($employees as $employee) {
            $stored = json_decode((string) $employee->face_descriptor, true);
            if (! is_array($stored) || count($stored) !== self::DESCRIPTOR_LENGTH) {
                continue;
            }

            $distance = $this->euclideanDistance($descriptor, $stored);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $employee;
            }
        }

        if ($best === null || $bestDistance > $threshold) {
            return null;
        }

        $confidence = max(0.0, min(1.0, 1.0 - ($bestDistance / $threshold)));

        return [
            'employee' => $best,
            'distance' => $bestDistance,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  list<float|int>  $descriptor
     * @return array{
     *     recognized: bool,
     *     employee_id?: int,
     *     employee_name?: string,
     *     event_type?: string,
     *     recorded_at?: string,
     *     confidence?: float,
     *     debounced?: bool
     * }
     */
    public function processPunch(array $descriptor): array
    {
        $match = $this->matchDescriptor($descriptor);
        if ($match === null) {
            return ['recognized' => false];
        }

        $employeeId = (int) $match['employee']->id;
        $now = now();
        $today = $now->toDateString();

        $lastToday = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, event_type, recorded_at
             FROM '.self::ATTENDANCE_TABLE.'
             WHERE employee_id = ? AND date(recorded_at) = ?
             ORDER BY recorded_at DESC, id DESC
             LIMIT 1',
            [$employeeId, $today]
        );

        if ($lastToday !== null) {
            $lastAt = strtotime((string) $lastToday->recorded_at);
            if ($lastAt !== false && ($now->getTimestamp() - $lastAt) < self::DEBOUNCE_SECONDS) {
                return [
                    'recognized' => true,
                    'employee_id' => $employeeId,
                    'employee_name' => (string) $match['employee']->name,
                    'debounced' => true,
                ];
            }
        }

        $eventType = ($lastToday === null || (string) $lastToday->event_type === 'clock_out')
            ? 'clock_in'
            : 'clock_out';

        $recordedAt = $now->toDateTimeString();
        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::ATTENDANCE_TABLE.' (employee_id, event_type, recorded_at, confidence)
             VALUES (?, ?, ?, ?)',
            [$employeeId, $eventType, $recordedAt, round($match['confidence'], 4)]
        );

        return [
            'recognized' => true,
            'employee_id' => $employeeId,
            'employee_name' => (string) $match['employee']->name,
            'event_type' => $eventType,
            'recorded_at' => $recordedAt,
            'confidence' => $match['confidence'],
        ];
    }

    /**
     * @return list<object>
     */
    public function listAttendance(string $dateFrom, string $dateTo): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT a.id, a.employee_id, a.event_type, a.recorded_at, a.confidence,
                    e.name AS employee_name, e.employee_code
             FROM '.self::ATTENDANCE_TABLE.' a
             INNER JOIN '.self::EMPLOYEES_TABLE.' e ON e.id = a.employee_id
             WHERE date(a.recorded_at) >= ? AND date(a.recorded_at) <= ?
             ORDER BY a.recorded_at DESC, a.id DESC',
            [$dateFrom, $dateTo]
        );
    }

    public function employeeHasFaceEnrolled(object $employee): bool
    {
        $descriptor = $employee->face_descriptor ?? null;

        return is_string($descriptor) && trim($descriptor) !== '';
    }

    /**
     * @param  list<float|int>  $descriptor
     */
    private function assertValidDescriptor(array $descriptor): void
    {
        if (count($descriptor) !== self::DESCRIPTOR_LENGTH) {
            throw new InvalidArgumentException('Face descriptor must contain exactly '.self::DESCRIPTOR_LENGTH.' values.');
        }

        foreach ($descriptor as $value) {
            if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
                throw new InvalidArgumentException('Face descriptor values must be numeric.');
            }
        }
    }

    /**
     * @param  list<float|int>  $a
     * @param  list<float|int>  $b
     */
    private function euclideanDistance(array $a, array $b): float
    {
        $sum = 0.0;
        for ($i = 0; $i < self::DESCRIPTOR_LENGTH; $i++) {
            $diff = (float) $a[$i] - (float) $b[$i];
            $sum += $diff * $diff;
        }

        return sqrt($sum);
    }

    private function normalizeEmployeeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = trim($code);

        return $code === '' ? null : $code;
    }

    private function settingsRow(): ?object
    {
        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, kiosk_token, match_threshold, created_at, updated_at
             FROM '.self::SETTINGS_TABLE.'
             WHERE id = 1
             LIMIT 1'
        );
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureDatabaseFileExists();

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::SETTINGS_TABLE.' (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                kiosk_token TEXT NOT NULL,
                match_threshold REAL NOT NULL DEFAULT '.self::DEFAULT_MATCH_THRESHOLD.',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::EMPLOYEES_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                employee_code TEXT,
                is_active INTEGER NOT NULL DEFAULT 1,
                face_descriptor TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE INDEX IF NOT EXISTS idx_face_id_employees_active
             ON '.self::EMPLOYEES_TABLE.' (is_active)'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::ATTENDANCE_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL,
                event_type TEXT NOT NULL CHECK (event_type IN (\'clock_in\', \'clock_out\')),
                recorded_at TEXT NOT NULL,
                confidence REAL,
                FOREIGN KEY (employee_id) REFERENCES '.self::EMPLOYEES_TABLE.' (id) ON DELETE CASCADE
            )'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE INDEX IF NOT EXISTS idx_face_id_attendance_employee_date
             ON '.self::ATTENDANCE_TABLE.' (employee_id, recorded_at)'
        );

        if ($this->settingsRow() === null) {
            $token = Str::random(64);
            $now = now()->toDateTimeString();
            DB::connection(self::CONNECTION)->insert(
                'INSERT INTO '.self::SETTINGS_TABLE.' (id, kiosk_token, match_threshold, created_at, updated_at)
                 VALUES (1, ?, ?, ?, ?)',
                [$token, self::DEFAULT_MATCH_THRESHOLD, $now, $now]
            );
        }

        $this->schemaChecked = true;
    }

    private function ensureDatabaseFileExists(): void
    {
        $configuredPath = config('database.connections.'.self::CONNECTION.'.database');
        if (! is_string($configuredPath) || $configuredPath === '' || $configuredPath === ':memory:') {
            return;
        }

        $directory = dirname($configuredPath);
        if (! File::isDirectory($directory)) {
            if (! File::makeDirectory($directory, 0755, true) && ! File::isDirectory($directory)) {
                throw new RuntimeException('Unable to create Face ID database directory.');
            }
        }

        if (! File::exists($configuredPath)) {
            if (File::put($configuredPath, '') === false) {
                throw new RuntimeException('Unable to create Face ID database file.');
            }
        }
    }
}
