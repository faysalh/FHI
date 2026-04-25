<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CitiesGovernorateSqliteService
{
    private const CONNECTION = 'deliveries_sqlite';

    private const GOVERNORATES_TABLE = 'city_governorates';

    private const MEMBERS_TABLE = 'city_governorate_members';

    private bool $schemaChecked = false;

    /**
     * @return list<object>
     */
    public function listGovernorates(): array
    {
        $this->ensureSchema();

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT g.id, g.name, g.governorate_city, g.updated_at,
                    COUNT(m.id) AS member_count
             FROM '.self::GOVERNORATES_TABLE.' AS g
             LEFT JOIN '.self::MEMBERS_TABLE.' AS m ON m.governorate_id = g.id
             GROUP BY g.id, g.name, g.governorate_city, g.updated_at
             ORDER BY g.name ASC'
        );

        return $rows;
    }

    /**
     * @return array{id:int,name:string,governorate_city:string,members:list<string>}|null
     */
    public function getGovernorateById(int $id): ?array
    {
        $this->ensureSchema();

        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, name, governorate_city
             FROM '.self::GOVERNORATES_TABLE.'
             WHERE id = ?
             LIMIT 1',
            [$id]
        );
        if ($row === null) {
            return null;
        }

        $members = DB::connection(self::CONNECTION)->select(
            'SELECT city_name
             FROM '.self::MEMBERS_TABLE.'
             WHERE governorate_id = ?
             ORDER BY city_name ASC',
            [$id]
        );

        return [
            'id' => (int) ($row->id ?? 0),
            'name' => trim((string) ($row->name ?? '')),
            'governorate_city' => trim((string) ($row->governorate_city ?? '')),
            'members' => array_values(array_map(
                static fn (object $m): string => trim((string) ($m->city_name ?? '')),
                $members
            )),
        ];
    }

    /**
     * @param  list<string>  $members
     */
    public function saveGovernorate(?int $id, string $name, string $governorateCity, array $members): int
    {
        $this->ensureSchema();

        $db = DB::connection(self::CONNECTION);
        $name = trim($name);
        $governorateCity = trim($governorateCity);
        $members = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $members
        ))));

        if ($governorateCity !== '' && ! in_array($governorateCity, $members, true)) {
            $members[] = $governorateCity;
        }

        if ($name === '' || $governorateCity === '') {
            throw new \InvalidArgumentException('Governorate label and city are required.');
        }

        if ($id !== null && $id > 0) {
            $existingById = $db->selectOne(
                'SELECT id
                 FROM '.self::GOVERNORATES_TABLE.'
                 WHERE id = ?
                 LIMIT 1',
                [$id]
            );
            if ($existingById === null) {
                $id = null;
            }
        }

        if (($id === null || $id <= 0) && $name !== '') {
            $existing = $db->selectOne(
                'SELECT id
                 FROM '.self::GOVERNORATES_TABLE.'
                 WHERE name = ? COLLATE NOCASE
                 LIMIT 1',
                [$name]
            );
            if ($existing !== null) {
                $id = (int) ($existing->id ?? 0);
            }
        }

        return $db->transaction(function () use ($db, $id, $name, $governorateCity, $members): int {
            $now = now()->toDateTimeString();
            if ($id !== null && $id > 0) {
                $db->update(
                    'UPDATE '.self::GOVERNORATES_TABLE.'
                     SET name = ?, governorate_city = ?, updated_at = ?
                     WHERE id = ?',
                    [$name, $governorateCity, $now, $id]
                );
                $govId = $id;
            } else {
                $db->insert(
                    'INSERT INTO '.self::GOVERNORATES_TABLE.' (name, governorate_city, created_at, updated_at)
                     VALUES (?, ?, ?, ?)',
                    [$name, $governorateCity, $now, $now]
                );
                $inserted = $db->selectOne('SELECT last_insert_rowid() AS id');
                $govId = (int) ($inserted->id ?? 0);
            }

            $db->delete(
                'DELETE FROM '.self::MEMBERS_TABLE.' WHERE governorate_id = ?',
                [$govId]
            );

            foreach ($members as $city) {
                $db->insert(
                    'INSERT INTO '.self::MEMBERS_TABLE.' (governorate_id, city_name)
                     VALUES (?, ?)',
                    [$govId, $city]
                );
            }

            return $govId;
        });
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $db = DB::connection(self::CONNECTION);
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::GOVERNORATES_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                governorate_city TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::MEMBERS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                governorate_id INTEGER NOT NULL,
                city_name TEXT NOT NULL
            )'
        );
        $db->statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_city_governorates_name ON '.self::GOVERNORATES_TABLE.' (name)');
        $db->statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_city_governorate_members_unique ON '.self::MEMBERS_TABLE.' (governorate_id, city_name)');

        $this->schemaChecked = true;
    }
}

