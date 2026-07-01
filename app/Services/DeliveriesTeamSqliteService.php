<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class DeliveriesTeamSqliteService
{
    private const CONNECTION = 'deliveries_sqlite';

    private const PEOPLE_TABLE = 'delivery_people';

    private const DAILY_TEAMS_TABLE = 'delivery_daily_teams';

    private const INVOICE_ASSIGNMENTS_TABLE = 'delivery_invoice_team_assignments';

    private bool $schemaChecked = false;

    /**
     * @return list<object>
     */
    public function listDrivers(): array
    {
        $this->ensureSchema();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, full_name, car_number, car_model
             FROM '.self::PEOPLE_TABLE.'
             WHERE person_type = ?
             ORDER BY full_name ASC',
            ['driver']
        );
    }

    /**
     * @return list<object>
     */
    public function listCompanions(): array
    {
        $this->ensureSchema();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, full_name
             FROM '.self::PEOPLE_TABLE.'
             WHERE person_type = ?
             ORDER BY full_name ASC',
            ['companion']
        );
    }

    public function addDriver(string $fullName, string $carNumber, string $carModel): void
    {
        $this->ensureSchema();

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::PEOPLE_TABLE.' (person_type, full_name, car_number, car_model, created_at)
             VALUES (?, ?, ?, ?, ?)',
            ['driver', $fullName, $carNumber, $carModel, now()->toDateTimeString()]
        );
    }

    public function addCompanion(string $fullName): void
    {
        $this->ensureSchema();

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::PEOPLE_TABLE.' (person_type, full_name, car_number, car_model, created_at)
             VALUES (?, ?, ?, ?, ?)',
            ['companion', $fullName, null, null, now()->toDateTimeString()]
        );
    }

    public function updateDriver(int $personId, string $fullName, string $carNumber, string $carModel): void
    {
        $this->ensureSchema();
        $this->assertPersonType($personId, 'driver');

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::PEOPLE_TABLE.'
             SET full_name = ?, car_number = ?, car_model = ?
             WHERE id = ? AND person_type = ?',
            [$fullName, $carNumber !== '' ? $carNumber : null, $carModel !== '' ? $carModel : null, $personId, 'driver']
        );
    }

    public function updateCompanion(int $personId, string $fullName): void
    {
        $this->ensureSchema();
        $this->assertPersonType($personId, 'companion');

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::PEOPLE_TABLE.'
             SET full_name = ?
             WHERE id = ? AND person_type = ?',
            [$fullName, $personId, 'companion']
        );
    }

    public function deleteDriver(int $personId): void
    {
        $this->ensureSchema();
        $this->assertPersonType($personId, 'driver');
        $this->deletePersonAndRelatedTeams($personId);
    }

    public function deleteCompanion(int $personId): void
    {
        $this->ensureSchema();
        $this->assertPersonType($personId, 'companion');
        $this->deletePersonAndRelatedTeams($personId);
    }

    public function addDailyTeam(string $teamDate, int $driverId, int $companionId): void
    {
        $this->ensureSchema();

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::DAILY_TEAMS_TABLE.' (team_date, driver_person_id, companion_person_id, created_at)
             VALUES (?, ?, ?, ?)',
            [$teamDate, $driverId, $companionId, now()->toDateTimeString()]
        );
    }

    public function deleteDailyTeam(int $teamId): void
    {
        $this->ensureSchema();
        if ($teamId <= 0) {
            return;
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::INVOICE_ASSIGNMENTS_TABLE.' WHERE team_id = ?',
            [$teamId]
        );
        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::DAILY_TEAMS_TABLE.' WHERE id = ?',
            [$teamId]
        );
    }

    /**
     * @return list<object>
     */
    public function listDailyTeamsForDate(string $teamDate): array
    {
        $this->ensureSchema();

        return DB::connection(self::CONNECTION)->select(
            'SELECT t.id, t.team_date, t.driver_person_id, t.companion_person_id,
                    d.full_name AS driver_name, d.car_number, d.car_model,
                    c.full_name AS companion_name
             FROM '.self::DAILY_TEAMS_TABLE.' AS t
             INNER JOIN '.self::PEOPLE_TABLE.' AS d ON d.id = t.driver_person_id
             INNER JOIN '.self::PEOPLE_TABLE.' AS c ON c.id = t.companion_person_id
             WHERE t.team_date = ?
             ORDER BY d.full_name ASC, c.full_name ASC',
            [$teamDate]
        );
    }

    /**
     * @return array<string, list<object>>
     */
    public function listDailyTeamsByDateRange(string $dateFrom, string $dateTo): array
    {
        $this->ensureSchema();

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT t.id, t.team_date, t.driver_person_id, t.companion_person_id,
                    d.full_name AS driver_name, d.car_number, d.car_model,
                    c.full_name AS companion_name
             FROM '.self::DAILY_TEAMS_TABLE.' AS t
             INNER JOIN '.self::PEOPLE_TABLE.' AS d ON d.id = t.driver_person_id
             INNER JOIN '.self::PEOPLE_TABLE.' AS c ON c.id = t.companion_person_id
             WHERE t.team_date >= ? AND t.team_date <= ?
             ORDER BY t.team_date ASC, d.full_name ASC, c.full_name ASC',
            [$dateFrom, $dateTo]
        );

        $grouped = [];
        foreach ($rows as $row) {
            $date = (string) ($row->team_date ?? '');
            if (! isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $row;
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public function invoiceIdsForTeam(int $teamId): array
    {
        $this->ensureSchema();

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT DISTINCT invoice_id
             FROM '.self::INVOICE_ASSIGNMENTS_TABLE.'
             WHERE team_id = ?',
            [$teamId]
        );

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->invoice_id ?? ''),
            array_filter($rows, static fn (object $row): bool => trim((string) ($row->invoice_id ?? '')) !== '')
        ));
    }

    /**
     * @return list<string>
     */
    public function invoiceIdsForTeamInDateRange(int $teamId, string $dateFrom, string $dateTo): array
    {
        $this->ensureSchema();

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT DISTINCT invoice_id
             FROM '.self::INVOICE_ASSIGNMENTS_TABLE.'
             WHERE team_id = ?
               AND document_date >= ?
               AND document_date <= ?',
            [$teamId, $dateFrom, $dateTo]
        );

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->invoice_id ?? ''),
            array_filter($rows, static fn (object $row): bool => trim((string) ($row->invoice_id ?? '')) !== '')
        ));
    }

    /**
     * @param  list<string>  $invoiceIds
     * @return array<string, object>
     */
    public function assignmentsByInvoiceIds(array $invoiceIds): array
    {
        $this->ensureSchema();

        $invoiceIds = array_values(array_unique(array_filter(array_map(static fn ($v): string => trim((string) $v), $invoiceIds))));
        if ($invoiceIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT a.invoice_id, a.team_id, t.team_date,
                    d.full_name AS driver_name, d.car_number, d.car_model,
                    c.full_name AS companion_name
             FROM '.self::INVOICE_ASSIGNMENTS_TABLE.' AS a
             INNER JOIN '.self::DAILY_TEAMS_TABLE.' AS t ON t.id = a.team_id
             INNER JOIN '.self::PEOPLE_TABLE.' AS d ON d.id = t.driver_person_id
             INNER JOIN '.self::PEOPLE_TABLE.' AS c ON c.id = t.companion_person_id
             WHERE a.invoice_id IN ('.$placeholders.')',
            $invoiceIds
        );

        $out = [];
        foreach ($rows as $row) {
            $invoiceId = (string) ($row->invoice_id ?? '');
            if ($invoiceId !== '') {
                $out[$invoiceId] = $row;
            }
        }

        return $out;
    }

    public function assignInvoiceTeam(string $invoiceId, string $documentDate, int $teamId): void
    {
        $this->ensureSchema();

        $exists = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id
             FROM '.self::INVOICE_ASSIGNMENTS_TABLE.'
             WHERE invoice_id = ?
             LIMIT 1',
            [$invoiceId]
        );

        if ($exists !== null) {
            DB::connection(self::CONNECTION)->update(
                'UPDATE '.self::INVOICE_ASSIGNMENTS_TABLE.'
                 SET team_id = ?, document_date = ?, updated_at = ?
                 WHERE invoice_id = ?',
                [$teamId, $documentDate, now()->toDateTimeString(), $invoiceId]
            );

            return;
        }

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::INVOICE_ASSIGNMENTS_TABLE.' (invoice_id, document_date, team_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$invoiceId, $documentDate, $teamId, now()->toDateTimeString(), now()->toDateTimeString()]
        );
    }

    public function clearTeamAssignments(int $teamId): int
    {
        $this->ensureSchema();

        return DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::INVOICE_ASSIGNMENTS_TABLE.' WHERE team_id = ?',
            [$teamId]
        );
    }

    /**
     * @return list<string>
     */
    public function listAllAssignedInvoiceIds(): array
    {
        $this->ensureSchema();

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT DISTINCT invoice_id FROM '.self::INVOICE_ASSIGNMENTS_TABLE
        );

        return array_values(array_filter(array_map(
            static fn (object $row): string => trim((string) ($row->invoice_id ?? '')),
            $rows
        )));
    }

    public function teamLabel(object $row): string
    {
        $driver = trim((string) ($row->driver_name ?? ''));
        $companion = trim((string) ($row->companion_name ?? ''));
        $carNumber = trim((string) ($row->car_number ?? ''));
        $carModel = trim((string) ($row->car_model ?? ''));

        $car = trim($carNumber.' '.$carModel);

        $parts = [];
        if ($driver !== '') {
            $parts[] = $driver;
        }
        if ($car !== '') {
            $parts[] = '(' . $car . ')';
        }
        if ($companion !== '') {
            $parts[] = '+ '.$companion;
        }

        return trim(implode(' ', $parts));
    }

    public function teamPeopleLabel(object $row): string
    {
        $driver = trim((string) ($row->driver_name ?? ''));
        $companion = trim((string) ($row->companion_name ?? ''));

        $parts = [];
        if ($driver !== '') {
            $parts[] = $driver;
        }
        if ($companion !== '') {
            $parts[] = '+ '.$companion;
        }

        return trim(implode(' ', $parts));
    }

    public function teamAssignmentOptionLabel(object $row): string
    {
        $date = trim((string) ($row->team_date ?? ''));
        $people = $this->teamPeopleLabel($row);

        if ($date === '') {
            return $people;
        }

        if ($people === '') {
            return $date;
        }

        return $date.' — '.$people;
    }

    private function assertPersonType(int $personId, string $expectedType): void
    {
        if ($personId <= 0) {
            throw new \InvalidArgumentException('Invalid person id.');
        }

        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, person_type FROM '.self::PEOPLE_TABLE.' WHERE id = ? LIMIT 1',
            [$personId]
        );

        if ($row === null) {
            throw new \InvalidArgumentException('Person not found.');
        }

        if ((string) ($row->person_type ?? '') !== $expectedType) {
            throw new \InvalidArgumentException('Person type mismatch.');
        }
    }

    private function deletePersonAndRelatedTeams(int $personId): void
    {
        $teamRows = DB::connection(self::CONNECTION)->select(
            'SELECT id FROM '.self::DAILY_TEAMS_TABLE.'
             WHERE driver_person_id = ? OR companion_person_id = ?',
            [$personId, $personId]
        );

        foreach ($teamRows as $teamRow) {
            $this->deleteDailyTeam((int) ($teamRow->id ?? 0));
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::PEOPLE_TABLE.' WHERE id = ?',
            [$personId]
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
            'CREATE TABLE IF NOT EXISTS '.self::PEOPLE_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                person_type TEXT NOT NULL,
                full_name TEXT NOT NULL,
                car_number TEXT NULL,
                car_model TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::DAILY_TEAMS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                team_date TEXT NOT NULL,
                driver_person_id INTEGER NOT NULL,
                companion_person_id INTEGER NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::INVOICE_ASSIGNMENTS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id TEXT NOT NULL UNIQUE,
                document_date TEXT NOT NULL,
                team_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $db->statement('CREATE INDEX IF NOT EXISTS idx_delivery_people_type_name ON '.self::PEOPLE_TABLE.' (person_type, full_name)');
        $db->statement('CREATE INDEX IF NOT EXISTS idx_delivery_daily_teams_date ON '.self::DAILY_TEAMS_TABLE.' (team_date)');
        $db->statement('CREATE INDEX IF NOT EXISTS idx_delivery_invoice_assignments_team_date ON '.self::INVOICE_ASSIGNMENTS_TABLE.' (team_id, document_date)');

        $this->schemaChecked = true;
    }

    private function ensureDatabaseFileExists(): void
    {
        /** @var mixed $configuredPath */
        $configuredPath = config('database.connections.'.self::CONNECTION.'.database');
        $path = trim((string) $configuredPath);
        if ($path === '') {
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

