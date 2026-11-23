<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\WorkingDays;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class HrSqliteService
{
    private const CONNECTION = 'hr_sqlite';

    private const EMPLOYEES = 'hr_employees';

    private const LEAVE = 'hr_leave_requests';

    public const SOURCE_DRIVER = 'delivery_driver';

    public const SOURCE_COMPANION = 'delivery_companion';

    public const SOURCE_FACE = 'face_id';

    public const SOURCE_OTHER = 'other';

    /** @var list<string> */
    public const SOURCE_TYPES = [
        self::SOURCE_DRIVER,
        self::SOURCE_COMPANION,
        self::SOURCE_FACE,
        self::SOURCE_OTHER,
    ];

    /** @var list<string> */
    public const LEAVE_TYPES = ['hourly', 'sick', 'unpaid', 'annual', 'other'];

    /** @var list<string> */
    public const LEAVE_STATUSES = ['pending', 'approved', 'rejected'];

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    /**
     * @return list<object>
     */
    public function listEmployees(): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, source_type, source_id, full_name, phone, address, job_title, hire_date, national_id,
                    emergency_name, emergency_relationship, emergency_phone, notes, is_active, created_at, updated_at
             FROM '.self::EMPLOYEES.'
             ORDER BY full_name COLLATE NOCASE ASC, id ASC'
        );
    }

    public function findEmployee(int $id): ?object
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, source_type, source_id, full_name, phone, address, job_title, hire_date, national_id,
                    emergency_name, emergency_relationship, emergency_phone, notes, is_active, created_at, updated_at
             FROM '.self::EMPLOYEES.'
             WHERE id = ?
             LIMIT 1',
            [$id]
        );
    }

    /**
     * @param  array{
     *     source_type: string,
     *     source_id: int|null,
     *     full_name: string,
     *     phone?: string|null,
     *     address?: string|null,
     *     job_title?: string|null,
     *     hire_date?: string|null,
     *     national_id?: string|null,
     *     emergency_name?: string|null,
     *     emergency_relationship?: string|null,
     *     emergency_phone?: string|null,
     *     notes?: string|null,
     *     is_active?: bool
     * }  $input
     */
    public function createEmployee(array $input): int
    {
        $this->ensureReady();
        $sourceType = $this->sourceType($input['source_type']);
        $sourceId = $sourceType === self::SOURCE_OTHER ? null : $this->positiveId($input['source_id'] ?? null);
        $name = $this->requiredText($input['full_name'] ?? '', 'Name');
        $this->assertSourceAvailable($sourceType, $sourceId);

        $now = now()->toDateTimeString();
        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::EMPLOYEES.' (
                source_type, source_id, full_name, phone, address, job_title, hire_date, national_id,
                emergency_name, emergency_relationship, emergency_phone, notes, is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sourceType,
                $sourceId,
                $name,
                $this->nullableText($input['phone'] ?? null),
                $this->nullableText($input['address'] ?? null),
                $this->nullableText($input['job_title'] ?? null),
                $this->nullableText($input['hire_date'] ?? null),
                $this->nullableText($input['national_id'] ?? null),
                $this->nullableText($input['emergency_name'] ?? null),
                $this->nullableText($input['emergency_relationship'] ?? null),
                $this->nullableText($input['emergency_phone'] ?? null),
                $this->nullableText($input['notes'] ?? null),
                ($input['is_active'] ?? true) ? 1 : 0,
                $now,
                $now,
            ]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    /**
     * @param  array{
     *     full_name: string,
     *     phone?: string|null,
     *     address?: string|null,
     *     job_title?: string|null,
     *     hire_date?: string|null,
     *     national_id?: string|null,
     *     emergency_name?: string|null,
     *     emergency_relationship?: string|null,
     *     emergency_phone?: string|null,
     *     notes?: string|null,
     *     is_active?: bool
     * }  $input
     */
    public function updateEmployee(int $id, array $input): void
    {
        $this->ensureReady();
        $existing = $this->findEmployee($id);
        if ($existing === null) {
            throw new InvalidArgumentException('That employee was not found.');
        }

        $name = $this->requiredText($input['full_name'] ?? '', 'Name');
        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::EMPLOYEES.'
             SET full_name = ?, phone = ?, address = ?, job_title = ?, hire_date = ?, national_id = ?,
                 emergency_name = ?, emergency_relationship = ?, emergency_phone = ?, notes = ?,
                 is_active = ?, updated_at = ?
             WHERE id = ?',
            [
                $name,
                $this->nullableText($input['phone'] ?? null),
                $this->nullableText($input['address'] ?? null),
                $this->nullableText($input['job_title'] ?? null),
                $this->nullableText($input['hire_date'] ?? null),
                $this->nullableText($input['national_id'] ?? null),
                $this->nullableText($input['emergency_name'] ?? null),
                $this->nullableText($input['emergency_relationship'] ?? null),
                $this->nullableText($input['emergency_phone'] ?? null),
                $this->nullableText($input['notes'] ?? null),
                ($input['is_active'] ?? true) ? 1 : 0,
                now()->toDateTimeString(),
                $id,
            ]
        );
    }

    /**
     * Keys already stored for linked people, such as "delivery_driver:4".
     *
     * @return list<string>
     */
    public function linkedSourceKeys(): array
    {
        $this->ensureReady();
        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT source_type, source_id FROM '.self::EMPLOYEES.' WHERE source_id IS NOT NULL'
        );
        $keys = [];
        foreach ($rows as $row) {
            $keys[] = (string) $row->source_type.':'.(int) $row->source_id;
        }

        return $keys;
    }

    /**
     * @param  array<string, string>  $namesByKey
     * @param  list<object>  $rows
     * @return list<object>
     */
    public function applySourceNames(array $rows, array $namesByKey): array
    {
        foreach ($rows as $row) {
            $row->source_label = self::sourceLabel((string) ($row->source_type ?? ''));
            $row->source_missing = false;
            if ((string) ($row->source_type ?? '') === self::SOURCE_OTHER) {
                continue;
            }
            $key = (string) ($row->source_type ?? '').':'.(int) ($row->source_id ?? 0);
            if (isset($namesByKey[$key]) && trim($namesByKey[$key]) !== '') {
                $row->full_name = $namesByKey[$key];
                continue;
            }
            $row->source_missing = true;
        }

        return $rows;
    }

    /**
     * @return list<object>
     */
    public function listLeave(string $status = 'all'): array
    {
        $this->ensureReady();
        $sql = 'SELECT l.id, l.employee_id, l.leave_type, l.start_date, l.end_date, l.start_time, l.end_time, l.reason, l.status,
                       l.requested_by_user_id, l.requested_by_name, l.decided_by_user_id, l.decided_by_name,
                       l.decided_at, l.decision_note, l.created_at, l.updated_at,
                       e.full_name AS employee_name, e.emergency_name, e.emergency_relationship, e.emergency_phone,
                       e.job_title, e.phone AS employee_phone, e.source_type, e.source_id
                FROM '.self::LEAVE.' AS l
                INNER JOIN '.self::EMPLOYEES.' AS e ON e.id = l.employee_id';
        $bindings = [];
        if (in_array($status, self::LEAVE_STATUSES, true)) {
            $sql .= ' WHERE l.status = ?';
            $bindings[] = $status;
        }
        $sql .= ' ORDER BY datetime(l.created_at) DESC, l.id DESC';

        return DB::connection(self::CONNECTION)->select($sql, $bindings);
    }

    public function findLeave(int $id): ?object
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT l.id, l.employee_id, l.leave_type, l.start_date, l.end_date, l.start_time, l.end_time, l.reason, l.status,
                    l.requested_by_user_id, l.requested_by_name, l.decided_by_user_id, l.decided_by_name,
                    l.decided_at, l.decision_note, l.created_at, l.updated_at,
                    e.full_name AS employee_name, e.emergency_name, e.emergency_relationship, e.emergency_phone,
                    e.job_title, e.phone AS employee_phone, e.source_type, e.source_id
             FROM '.self::LEAVE.' AS l
             INNER JOIN '.self::EMPLOYEES.' AS e ON e.id = l.employee_id
             WHERE l.id = ?
             LIMIT 1',
            [$id]
        );
    }

    public function createLeave(
        int $employeeId,
        string $leaveType,
        string $startDate,
        string $endDate,
        string $reason,
        ?int $requestedByUserId,
        string $requestedByName,
        ?string $startTime = null,
        ?string $endTime = null,
    ): int {
        $this->ensureReady();
        $employee = $this->findEmployee($employeeId);
        if ($employee === null) {
            throw new InvalidArgumentException('That employee was not found.');
        }
        if ((int) ($employee->is_active ?? 0) !== 1) {
            throw new InvalidArgumentException('Leave can only be filed for an active employee.');
        }
        if (! in_array($leaveType, self::LEAVE_TYPES, true)) {
            throw new InvalidArgumentException('Choose a leave type.');
        }
        $start = $this->dateOnly($startDate);
        $reason = $this->requiredText($reason, 'Reason');
        $requestedByName = $this->requiredText($requestedByName, 'Requester');
        $storedStartTime = null;
        $storedEndTime = null;

        if ($leaveType === 'hourly') {
            $end = $start;
            $storedStartTime = $this->timeOnly($startTime);
            $storedEndTime = $this->timeOnly($endTime);
            if (self::hourlyMinutes($storedStartTime, $storedEndTime) <= 0) {
                throw new InvalidArgumentException('The end time has to be after the start time.');
            }
        } else {
            $end = $this->dateOnly($endDate);
            if ($end < $start) {
                throw new InvalidArgumentException('The end date cannot be before the start date.');
            }
            if (self::countLeaveDays($start, $end) < 1) {
                throw new InvalidArgumentException('That range is only Fridays or holidays, so there is no leave to file.');
            }
        }

        $now = now()->toDateTimeString();

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::LEAVE.' (
                employee_id, leave_type, start_date, end_date, start_time, end_time, reason, status,
                requested_by_user_id, requested_by_name, decided_by_user_id, decided_by_name,
                decided_at, decision_note, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, NULL, NULL, NULL, NULL, ?, ?)',
            [$employeeId, $leaveType, $start, $end, $storedStartTime, $storedEndTime, $reason, $requestedByUserId, $requestedByName, $now, $now]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function decideLeave(int $id, string $status, ?string $note, ?int $userId, string $userName): void
    {
        $this->ensureReady();
        $row = $this->findLeave($id);
        if ($row === null) {
            throw new InvalidArgumentException('That leave request was not found.');
        }
        if ((string) ($row->status ?? '') !== 'pending') {
            throw new InvalidArgumentException('This request has already been decided.');
        }
        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose approve or reject.');
        }

        $now = now()->toDateTimeString();
        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::LEAVE.'
             SET status = ?, decided_by_user_id = ?, decided_by_name = ?, decided_at = ?, decision_note = ?, updated_at = ?
             WHERE id = ? AND status = \'pending\'',
            [$status, $userId, $this->requiredText($userName, 'Manager'), $now, $this->nullableText($note), $now, $id]
        );
    }

    /**
     * @param  array<string, string>  $namesByKey
     */
    public function presentLeave(object $row, array $namesByKey): object
    {
        if ((string) ($row->source_type ?? '') !== self::SOURCE_OTHER) {
            $key = (string) ($row->source_type ?? '').':'.(int) ($row->source_id ?? 0);
            if (isset($namesByKey[$key]) && trim($namesByKey[$key]) !== '') {
                $row->employee_name = $namesByKey[$key];
            }
        }
        $row->type_label = self::leaveTypeLabel((string) ($row->leave_type ?? ''));
        $row->status_label = self::statusLabel((string) ($row->status ?? ''));
        if ((string) ($row->leave_type ?? '') === 'hourly') {
            $minutes = self::hourlyMinutes((string) ($row->start_time ?? ''), (string) ($row->end_time ?? ''));
            $row->duration_minutes = $minutes;
            $row->day_count = 0;
            $row->duration_label = self::hoursLabel($minutes);
            $row->span_label = trim((string) ($row->start_date ?? '')).' '.trim((string) ($row->start_time ?? '')).'–'.trim((string) ($row->end_time ?? ''));
        } else {
            $days = self::countLeaveDays((string) ($row->start_date ?? ''), (string) ($row->end_date ?? ''));
            $row->duration_minutes = null;
            $row->day_count = $days;
            $row->duration_label = $days.' '.($days === 1 ? 'day' : 'days');
            $row->span_label = trim((string) ($row->start_date ?? '')).' – '.trim((string) ($row->end_date ?? ''));
        }

        return $row;
    }

    /**
     * Working days in an inclusive range. Fridays and configured holidays are left out.
     * A range that is only those days counts as 0.
     */
    public static function countLeaveDays(string $start, string $end): int
    {
        try {
            $from = Carbon::parse($start)->startOfDay();
            $to = Carbon::parse($end)->startOfDay();
        } catch (\Throwable) {
            return 0;
        }
        if ($from->gt($to)) {
            return 0;
        }

        $count = 0;
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            if (WorkingDays::isBusinessDay($cursor, excludeFridays: true, excludeConfiguredHolidays: true)) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }

    public static function hourlyMinutes(string $startTime, string $endTime): int
    {
        try {
            $start = Carbon::createFromFormat('H:i', $startTime);
            $end = Carbon::createFromFormat('H:i', $endTime);
        } catch (\Throwable) {
            return 0;
        }
        if ($start === false || $end === false || $end->lte($start)) {
            return 0;
        }

        return (int) $start->diffInMinutes($end, true);
    }

    public static function hoursLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0 hours';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($rest === 0) {
            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }
        if ($hours === 0) {
            return $rest.' minutes';
        }

        return $hours.' '.($hours === 1 ? 'hour' : 'hours').' '.$rest.' minutes';
    }

    public static function inclusiveDays(string $start, string $end): int
    {
        try {
            $from = Carbon::parse($start)->startOfDay();
            $to = Carbon::parse($end)->startOfDay();
        } catch (\Throwable) {
            return 0;
        }

        return (int) abs($from->diffInDays($to)) + 1;
    }

    public static function sourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            self::SOURCE_DRIVER => 'Driver',
            self::SOURCE_COMPANION => 'Companion',
            self::SOURCE_FACE => 'Face ID',
            self::SOURCE_OTHER => 'Other',
            default => 'Other',
        };
    }

    public static function leaveTypeLabel(string $leaveType): string
    {
        return match ($leaveType) {
            'hourly' => 'Hourly',
            'annual' => 'Annual',
            'sick' => 'Sick',
            'unpaid' => 'Unpaid',
            'other' => 'Other',
            default => 'Other',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => 'Pending',
        };
    }

    private function assertSourceAvailable(string $sourceType, ?int $sourceId): void
    {
        if ($sourceType === self::SOURCE_OTHER) {
            return;
        }
        if ($sourceId === null) {
            throw new InvalidArgumentException('Choose a person.');
        }
        $existing = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id FROM '.self::EMPLOYEES.' WHERE source_type = ? AND source_id = ? LIMIT 1',
            [$sourceType, $sourceId]
        );
        if ($existing !== null) {
            throw new InvalidArgumentException('That person is already on the employee file.');
        }
    }

    private function sourceType(string $sourceType): string
    {
        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException('Choose where this person comes from.');
        }

        return $sourceType;
    }

    private function positiveId(mixed $id): int
    {
        $value = (int) $id;
        if ($value <= 0) {
            throw new InvalidArgumentException('Choose a person.');
        }

        return $value;
    }

    private function requiredText(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($label.' is required.');
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function dateOnly(string $value): string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Enter a valid date.');
        }
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureDatabaseFileExists();
        $db = DB::connection(self::CONNECTION);
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::EMPLOYEES.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_type TEXT NOT NULL,
                source_id INTEGER NULL,
                full_name TEXT NOT NULL,
                phone TEXT NULL,
                address TEXT NULL,
                job_title TEXT NULL,
                hire_date TEXT NULL,
                national_id TEXT NULL,
                emergency_name TEXT NULL,
                emergency_relationship TEXT NULL,
                emergency_phone TEXT NULL,
                notes TEXT NULL,
                is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_hr_employees_source
             ON '.self::EMPLOYEES.' (source_type, source_id)
             WHERE source_id IS NOT NULL'
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::LEAVE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL,
                leave_type TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                start_time TEXT NULL,
                end_time TEXT NULL,
                reason TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                requested_by_user_id INTEGER NULL,
                requested_by_name TEXT NOT NULL,
                decided_by_user_id INTEGER NULL,
                decided_by_name TEXT NULL,
                decided_at TEXT NULL,
                decision_note TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_hr_leave_status_created
             ON '.self::LEAVE.' (status, created_at)'
        );
        $this->ensureColumn(self::LEAVE, 'start_time', 'TEXT NULL');
        $this->ensureColumn(self::LEAVE, 'end_time', 'TEXT NULL');

        $this->schemaChecked = true;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $columns = DB::connection(self::CONNECTION)->select('PRAGMA table_info('.$table.')');
        foreach ($columns as $col) {
            if (strcasecmp((string) ($col->name ?? ''), $column) === 0) {
                return;
            }
        }

        DB::connection(self::CONNECTION)->statement(
            'ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition
        );
    }

    private function timeOnly(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if (preg_match('/^(\d{2}):(\d{2})/', $text, $match) !== 1) {
            throw new InvalidArgumentException('Enter a start and end time.');
        }

        return $match[1].':'.$match[2];
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
