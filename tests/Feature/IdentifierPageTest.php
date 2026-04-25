<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class IdentifierPageTest extends TestCase
{
    public function test_identifier_page_renders_successfully(): void
    {
        $response = $this->get(route('reports.identifier.index'));

        $response->assertOk();
        $response->assertSee('Identifier', false);
        $response->assertSee('Salesman', false);
        $response->assertSee('City', false);
        $response->assertSee('Item category', false);
    }
}
