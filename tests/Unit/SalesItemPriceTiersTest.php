<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SalesItemPriceTiers;
use PHPUnit\Framework\TestCase;

class SalesItemPriceTiersTest extends TestCase
{
    public function test_account_group_uses_the_matching_sale_price_column(): void
    {
        $this->assertSame(1, SalesItemPriceTiers::columnForAccountGroup(0));
        $this->assertSame(2, SalesItemPriceTiers::columnForAccountGroup(1));
        $this->assertSame(3, SalesItemPriceTiers::columnForAccountGroup(2));
        $this->assertSame(4, SalesItemPriceTiers::columnForAccountGroup(3));
        $this->assertSame(5, SalesItemPriceTiers::columnForAccountGroup(4));
        $this->assertSame('ماركيت', SalesItemPriceTiers::label(3));
    }
}
