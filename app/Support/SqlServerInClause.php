<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Build IN (...) conditions for SQL Server (2100-parameter limit per statement).
 *
 * Lists larger than CHUNK_SIZE must be queried in separate statements (see conditionChunks).
 */
final class SqlServerInClause
{
    public const CHUNK_SIZE = 2000;

    /**
     * Single-statement IN / = condition (at most CHUNK_SIZE values).
     *
     * @param  list<string>  $values
     * @return array{0: string, 1: list<string>}
     */
    public static function condition(string $columnExpression, array $values): array
    {
        $values = self::normalizeValues($values);
        if ($values === []) {
            return ['0 = 1', []];
        }

        if (count($values) > self::CHUNK_SIZE) {
            throw new InvalidArgumentException(
                'Too many values for one SQL Server IN clause; use conditionChunks() and run separate queries.'
            );
        }

        if (count($values) === 1) {
            return ["{$columnExpression} = ?", $values];
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));

        return ["{$columnExpression} IN ({$placeholders})", $values];
    }

    /**
     * @param  list<string>  $values
     * @return list<array{0: string, 1: list<string>}>
     */
    public static function conditionChunks(string $columnExpression, array $values): array
    {
        $values = self::normalizeValues($values);
        if ($values === []) {
            return [['0 = 1', []]];
        }

        $chunks = [];
        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            [$sql, $bindings] = self::condition($columnExpression, $chunk);
            $chunks[] = [$sql, $bindings];
        }

        return $chunks;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function normalizeValues(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $values
        ), static fn (string $value): bool => $value !== '')));
    }
}
