<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MarketStudySqliteService
{
    private const CONNECTION = 'market_study_sqlite';

    private const TABLE = 'market_price_entries';

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    /**
     * @return array{
     *     market: string,
     *     item: string,
     *     brand: string,
     *     date_from: string,
     *     date_to: string,
     *     q: string
     * }
     */
    public static function normalizeFilters(
        string $market = '',
        string $item = '',
        string $brand = '',
        string $dateFrom = '',
        string $dateTo = '',
        string $q = ''
    ): array {
        return [
            'market' => trim($market),
            'item' => trim($item),
            'brand' => trim($brand),
            'date_from' => trim($dateFrom),
            'date_to' => trim($dateTo),
            'q' => trim($q),
        ];
    }

    /**
     * @param  array{
     *     market?: string,
     *     item?: string,
     *     brand?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     q?: string
     *  }  $filters
     * @return list<object>
     */
    public function listEntries(array $filters = []): array
    {
        $this->ensureReady();
        $filters = self::normalizeFilters(
            (string) ($filters['market'] ?? ''),
            (string) ($filters['item'] ?? ''),
            (string) ($filters['brand'] ?? ''),
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
            (string) ($filters['q'] ?? '')
        );

        [$where, $bindings] = $this->buildWhere($filters);

        $sql = 'SELECT id, market_name, item_name, brand, price_per_kg, logged_on, notes,
                       created_by_user_id, updated_by_user_id, created_at, updated_at
                FROM '.self::TABLE;
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY date(logged_on) DESC, id DESC';

        return DB::connection(self::CONNECTION)->select($sql, $bindings);
    }

    public function find(int $entryId): ?object
    {
        $this->ensureReady();

        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, market_name, item_name, brand, price_per_kg, logged_on, notes,
                    created_by_user_id, updated_by_user_id, created_at, updated_at
             FROM '.self::TABLE.'
             WHERE id = ?',
            [$entryId]
        );

        return $row ?: null;
    }

    public function createEntry(
        string $marketName,
        string $itemName,
        string $brand,
        float $pricePerKg,
        string $loggedOn,
        ?string $notes,
        ?int $createdByUserId
    ): int {
        $this->ensureReady();
        $now = now()->toDateTimeString();
        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::TABLE.' (
                market_name, item_name, brand, price_per_kg, logged_on, notes,
                created_by_user_id, updated_by_user_id, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                trim($marketName),
                trim($itemName),
                trim($brand),
                $pricePerKg,
                trim($loggedOn),
                $notes,
                $createdByUserId,
                $createdByUserId,
                $now,
                $now,
            ]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function updateEntry(
        int $entryId,
        string $marketName,
        string $itemName,
        string $brand,
        float $pricePerKg,
        string $loggedOn,
        ?string $notes,
        ?int $updatedByUserId
    ): bool {
        $this->ensureReady();
        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        $affected = DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::TABLE.'
             SET market_name = ?, item_name = ?, brand = ?, price_per_kg = ?, logged_on = ?,
                 notes = ?, updated_by_user_id = ?, updated_at = ?
             WHERE id = ?',
            [
                trim($marketName),
                trim($itemName),
                trim($brand),
                $pricePerKg,
                trim($loggedOn),
                $notes,
                $updatedByUserId,
                now()->toDateTimeString(),
                $entryId,
            ]
        );

        return $affected > 0;
    }

    public function deleteEntry(int $entryId): bool
    {
        $this->ensureReady();

        $affected = DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::TABLE.' WHERE id = ?',
            [$entryId]
        );

        return $affected > 0;
    }

    /**
     * Distinct past item suggestions with latest brand (and market) for autocomplete.
     *
     * @return list<array{item_name: string, brand: string, market_name: string, label: string}>
     */
    public function suggestItems(string $q, int $limit = 20): array
    {
        $this->ensureReady();
        $q = trim($q);
        $limit = max(1, min(50, $limit));

        $bindings = [];
        $where = '';
        if ($q !== '') {
            $where = ' WHERE item_name LIKE ? OR brand LIKE ? OR market_name LIKE ?';
            $needle = '%'.$q.'%';
            $bindings = [$needle, $needle, $needle];
        }

        $sql = 'SELECT item_name, brand, market_name
                FROM '.self::TABLE.$where.'
                ORDER BY datetime(updated_at) DESC, id DESC
                LIMIT 200';

        $rows = DB::connection(self::CONNECTION)->select($sql, $bindings);
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $item = trim((string) ($row->item_name ?? ''));
            if ($item === '') {
                continue;
            }
            $key = mb_strtolower($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $brand = trim((string) ($row->brand ?? ''));
            $market = trim((string) ($row->market_name ?? ''));
            $label = $brand !== '' ? $item.' — '.$brand : $item;
            $out[] = [
                'item_name' => $item,
                'brand' => $brand,
                'market_name' => $market,
                'label' => $label,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function distinctColumn(string $column): array
    {
        $this->ensureReady();
        $allowed = ['market_name', 'item_name', 'brand'];
        if (! in_array($column, $allowed, true)) {
            return [];
        }

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT DISTINCT '.$column.' AS value
             FROM '.self::TABLE.'
             WHERE TRIM('.$column.') != \'\'
             ORDER BY '.$column.' COLLATE NOCASE ASC'
        );

        $out = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row->value ?? ''));
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array{
     *     market?: string,
     *     item?: string,
     *     brand?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     q?: string
     *  }  $filters
     * @return array{
     *     today_price: float|null,
     *     today_count: int,
     *     range_average: float|null,
     *     range_count: int,
     *     delta_pct: float|null
     * }
     */
    public function insightStats(array $filters = [], ?string $today = null): array
    {
        $this->ensureReady();
        $filters = self::normalizeFilters(
            (string) ($filters['market'] ?? ''),
            (string) ($filters['item'] ?? ''),
            (string) ($filters['brand'] ?? ''),
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
            (string) ($filters['q'] ?? '')
        );
        $today = trim((string) ($today ?? now()->toDateString()));

        $todayFilters = $filters;
        $todayFilters['date_from'] = $today;
        $todayFilters['date_to'] = $today;
        [$todayWhere, $todayBindings] = $this->buildWhere($todayFilters);
        $todaySql = 'SELECT AVG(price_per_kg) AS avg_price, COUNT(*) AS cnt FROM '.self::TABLE;
        if ($todayWhere !== []) {
            $todaySql .= ' WHERE '.implode(' AND ', $todayWhere);
        }
        $todayRow = DB::connection(self::CONNECTION)->selectOne($todaySql, $todayBindings);
        $todayCount = (int) ($todayRow->cnt ?? 0);
        $todayPrice = $todayCount > 0 ? (float) $todayRow->avg_price : null;

        [$rangeWhere, $rangeBindings] = $this->buildWhere($filters);
        $rangeSql = 'SELECT AVG(price_per_kg) AS avg_price, COUNT(*) AS cnt FROM '.self::TABLE;
        if ($rangeWhere !== []) {
            $rangeSql .= ' WHERE '.implode(' AND ', $rangeWhere);
        }
        $rangeRow = DB::connection(self::CONNECTION)->selectOne($rangeSql, $rangeBindings);
        $rangeCount = (int) ($rangeRow->cnt ?? 0);
        $rangeAverage = $rangeCount > 0 ? (float) $rangeRow->avg_price : null;

        $deltaPct = null;
        if ($todayPrice !== null && $rangeAverage !== null && $rangeAverage != 0.0) {
            $deltaPct = (($todayPrice - $rangeAverage) / $rangeAverage) * 100.0;
        }

        return [
            'today_price' => $todayPrice,
            'today_count' => $todayCount,
            'range_average' => $rangeAverage,
            'range_count' => $rangeCount,
            'delta_pct' => $deltaPct,
        ];
    }

    /**
     * Chart payloads: labels (Y-m-d) and one or more series of daily average prices.
     *
     * @param  array{
     *     market?: string,
     *     item?: string,
     *     brand?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     q?: string
     *  }  $filters
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<float|null>}>}
     */
    public function chartSeries(array $filters = [], int $maxSeries = 8): array
    {
        $this->ensureReady();
        $filters = self::normalizeFilters(
            (string) ($filters['market'] ?? ''),
            (string) ($filters['item'] ?? ''),
            (string) ($filters['brand'] ?? ''),
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
            (string) ($filters['q'] ?? '')
        );
        $maxSeries = max(1, min(12, $maxSeries));

        $splitByBrand = $filters['item'] !== '' && $filters['brand'] === '';

        if ($splitByBrand) {
            [$where, $bindings] = $this->buildWhere($filters);
            $brandSql = 'SELECT brand, AVG(price_per_kg) AS avg_price, COUNT(*) AS cnt
                         FROM '.self::TABLE;
            if ($where !== []) {
                $brandSql .= ' WHERE '.implode(' AND ', $where);
            }
            $brandSql .= ' GROUP BY brand ORDER BY cnt DESC, brand COLLATE NOCASE ASC LIMIT '.$maxSeries;
            $brands = DB::connection(self::CONNECTION)->select($brandSql, $bindings);

            $labels = $this->collectDateLabels($filters);
            $datasets = [];
            foreach ($brands as $brandRow) {
                $brand = trim((string) ($brandRow->brand ?? ''));
                $brandFilters = $filters;
                $brandFilters['brand'] = $brand;
                $byDate = $this->dailyAveragesMap($brandFilters);
                $data = [];
                foreach ($labels as $date) {
                    $data[] = $byDate[$date] ?? null;
                }
                $datasets[] = [
                    'label' => $brand !== '' ? $brand : '(no brand)',
                    'data' => $data,
                ];
            }

            if ($datasets === []) {
                return ['labels' => [], 'datasets' => []];
            }

            return ['labels' => $labels, 'datasets' => $datasets];
        }

        $labels = $this->collectDateLabels($filters);
        $byDate = $this->dailyAveragesMap($filters);
        $data = [];
        foreach ($labels as $date) {
            $data[] = $byDate[$date] ?? null;
        }

        $seriesLabel = 'Price / KG';
        $parts = array_filter([
            $filters['item'] !== '' ? $filters['item'] : null,
            $filters['brand'] !== '' ? $filters['brand'] : null,
            $filters['market'] !== '' ? $filters['market'] : null,
        ]);
        if ($parts !== []) {
            $seriesLabel = implode(' · ', $parts);
        }

        if ($labels === []) {
            return ['labels' => [], 'datasets' => []];
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $seriesLabel,
                    'data' => $data,
                ],
            ],
        ];
    }

    /**
     * @param  array{
     *     market: string,
     *     item: string,
     *     brand: string,
     *     date_from: string,
     *     date_to: string,
     *     q: string
     *  }  $filters
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $bindings = [];

        if ($filters['market'] !== '') {
            $where[] = 'market_name = ?';
            $bindings[] = $filters['market'];
        }
        if ($filters['item'] !== '') {
            $where[] = 'item_name = ?';
            $bindings[] = $filters['item'];
        }
        if ($filters['brand'] !== '') {
            $where[] = 'brand = ?';
            $bindings[] = $filters['brand'];
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'date(logged_on) >= date(?)';
            $bindings[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'date(logged_on) <= date(?)';
            $bindings[] = $filters['date_to'];
        }
        if ($filters['q'] !== '') {
            $where[] = '(market_name LIKE ? OR item_name LIKE ? OR brand LIKE ? OR IFNULL(notes, \'\') LIKE ?)';
            $needle = '%'.$filters['q'].'%';
            $bindings[] = $needle;
            $bindings[] = $needle;
            $bindings[] = $needle;
            $bindings[] = $needle;
        }

        return [$where, $bindings];
    }

    /**
     * @param  array{
     *     market: string,
     *     item: string,
     *     brand: string,
     *     date_from: string,
     *     date_to: string,
     *     q: string
     *  }  $filters
     * @return list<string>
     */
    private function collectDateLabels(array $filters): array
    {
        [$where, $bindings] = $this->buildWhere($filters);
        $sql = 'SELECT DISTINCT date(logged_on) AS d FROM '.self::TABLE;
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY date(logged_on) ASC';

        $rows = DB::connection(self::CONNECTION)->select($sql, $bindings);
        $labels = [];
        foreach ($rows as $row) {
            $d = trim((string) ($row->d ?? ''));
            if ($d !== '') {
                $labels[] = $d;
            }
        }

        return $labels;
    }

    /**
     * @param  array{
     *     market: string,
     *     item: string,
     *     brand: string,
     *     date_from: string,
     *     date_to: string,
     *     q: string
     *  }  $filters
     * @return array<string, float>
     */
    private function dailyAveragesMap(array $filters): array
    {
        [$where, $bindings] = $this->buildWhere($filters);
        $sql = 'SELECT date(logged_on) AS d, AVG(price_per_kg) AS avg_price
                FROM '.self::TABLE;
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' GROUP BY date(logged_on) ORDER BY date(logged_on) ASC';

        $rows = DB::connection(self::CONNECTION)->select($sql, $bindings);
        $map = [];
        foreach ($rows as $row) {
            $d = trim((string) ($row->d ?? ''));
            if ($d === '') {
                continue;
            }
            $map[$d] = round((float) $row->avg_price, 4);
        }

        return $map;
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
                market_name TEXT NOT NULL,
                item_name TEXT NOT NULL,
                brand TEXT NOT NULL,
                price_per_kg REAL NOT NULL,
                logged_on TEXT NOT NULL,
                notes TEXT NULL,
                created_by_user_id INTEGER NULL,
                updated_by_user_id INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_market_price_logged_on
             ON '.self::TABLE.' (logged_on)'
        );
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_market_price_item_brand
             ON '.self::TABLE.' (item_name, brand)'
        );
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_market_price_market
             ON '.self::TABLE.' (market_name)'
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
