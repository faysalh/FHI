<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SchemaExplorerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class SchemaExplorerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_schema_browser_page_renders_successfully(): void
    {
        $service = Mockery::mock(SchemaExplorerService::class);
        $service->shouldReceive('browse')->once()->andReturn([
            'tables' => [
                [
                    'schema' => 'dbo',
                    'table' => 'Customers',
                    'full_name' => 'dbo.Customers',
                    'column_count' => 5,
                    'row_count' => 12,
                ],
            ],
            'selected_table' => [
                'schema' => 'dbo',
                'table' => 'Customers',
                'full_name' => 'dbo.Customers',
            ],
            'columns' => [
                [
                    'name' => 'CustomerName',
                    'data_type' => 'nvarchar',
                    'is_nullable' => 'NO',
                    'max_length' => 255,
                ],
            ],
            'rows' => new LengthAwarePaginator(
                items: [(object) ['CustomerName' => 'Acme Corp']],
                total: 1,
                perPage: 10,
                currentPage: 1,
            ),
            'common_column_names' => ['CustomerName'],
            'search_query' => '',
            'search_query_input' => '',
            'search_hits' => [],
        ]);

        $this->app->instance(SchemaExplorerService::class, $service);

        $response = $this->get('/reports/schema');

        $response->assertOk();
        $response->assertSee('Sales & reporting schema');
        $response->assertSee('Column names common to all browsable tables');
        $response->assertSee('dbo.Customers');
        $response->assertSee('12 rows');
        $response->assertSee('Acme Corp');
        $response->assertDontSee('sysdiagrams');
    }
}
