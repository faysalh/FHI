<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReceiptBookletRanges;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiptBookletRangesTest extends TestCase
{
    #[Test]
    public function single_booklet_when_range_is_fifty_numbers(): void
    {
        $booklets = ReceiptBookletRanges::split(2051, 2100);

        $this->assertCount(1, $booklets);
        $this->assertSame(['start' => 2051, 'end' => 2100], $booklets[0]);
    }

    #[Test]
    public function multiple_booklets_split_in_fifty_number_chunks(): void
    {
        $booklets = ReceiptBookletRanges::split(2051, 2200);

        $this->assertCount(3, $booklets);
        $this->assertSame(['start' => 2051, 'end' => 2100], $booklets[0]);
        $this->assertSame(['start' => 2101, 'end' => 2150], $booklets[1]);
        $this->assertSame(['start' => 2151, 'end' => 2200], $booklets[2]);
    }

    #[Test]
    public function four_booklets_when_range_spans_two_hundred_numbers(): void
    {
        $booklets = ReceiptBookletRanges::split(2051, 2250);

        $this->assertCount(4, $booklets);
        $this->assertSame(2250, $booklets[3]['end']);
    }
}
