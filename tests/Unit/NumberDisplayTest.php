<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NumberDisplay;
use PHPUnit\Framework\TestCase;

class NumberDisplayTest extends TestCase
{
    public function test_whole_numbers_have_no_fraction(): void
    {
        $this->assertSame('0', NumberDisplay::format(0));
        $this->assertSame('100', NumberDisplay::format(100));
        $this->assertSame('1,234', NumberDisplay::format(1234));
        $this->assertSame('100', NumberDisplay::format(100.0));
    }

    public function test_fractional_values_are_rounded_to_whole_numbers(): void
    {
        $this->assertSame('5,001', NumberDisplay::format(5000.5));
        $this->assertSame('42', NumberDisplay::format(42.25));
        $this->assertSame('2', NumberDisplay::format(1.5));
    }

    public function test_null_and_empty_string_are_zero(): void
    {
        $this->assertSame('0', NumberDisplay::format(null));
        $this->assertSame('0', NumberDisplay::format(''));
    }
}
