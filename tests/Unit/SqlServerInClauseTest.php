<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SqlServerInClause;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SqlServerInClauseTest extends TestCase
{
    public function test_empty_values_return_false_condition(): void
    {
        [$sql, $bindings] = SqlServerInClause::condition('col', []);

        $this->assertSame('0 = 1', $sql);
        $this->assertSame([], $bindings);
    }

    public function test_single_value_uses_equality(): void
    {
        [$sql, $bindings] = SqlServerInClause::condition('col', ['a']);

        $this->assertSame('col = ?', $sql);
        $this->assertSame(['a'], $bindings);
    }

    public function test_multiple_values_use_parameterized_in_clause(): void
    {
        [$sql, $bindings] = SqlServerInClause::condition('col', ['a', 'b']);

        $this->assertSame('col IN (?,?)', $sql);
        $this->assertSame(['a', 'b'], $bindings);
    }

    public function test_condition_rejects_more_than_chunk_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $values = array_map(static fn (int $i): string => (string) $i, range(1, SqlServerInClause::CHUNK_SIZE + 1));
        SqlServerInClause::condition('col', $values);
    }

    public function test_condition_chunks_split_large_lists(): void
    {
        $values = array_map(static fn (int $i): string => (string) $i, range(1, 2500));

        $chunks = SqlServerInClause::conditionChunks('col', $values);

        $this->assertCount(2, $chunks);
        $this->assertSame(SqlServerInClause::CHUNK_SIZE, substr_count($chunks[0][0], '?'));
        $this->assertSame(500, substr_count($chunks[1][0], '?'));
        $this->assertCount(2500, array_merge($chunks[0][1], $chunks[1][1]));
    }
}
