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
            'view' => 'browse',
            'relations' => [],
            'relation_diagram_lines' => [],
        ]);

        $this->app->instance(SchemaExplorerService::class, $service);

        $response = $this->get('/reports/schema');

        $response->assertOk();
        $response->assertSee('Sales & reporting schema');
        $response->assertSee('Relations diagram');
        $response->assertSee('Constraint breakdown');
        $response->assertSee('Column names common to all browsable tables');
        $response->assertSee('dbo.Customers');
        $response->assertSee('12 rows');
        $response->assertSee('Acme Corp');
        $response->assertDontSee('sysdiagrams');
    }

    public function test_schema_relations_diagram_tab_renders_relation_lines(): void
    {
        $service = Mockery::mock(SchemaExplorerService::class);
        $service->shouldReceive('browse')->once()->andReturn([
            'tables' => [],
            'selected_table' => null,
            'columns' => [],
            'rows' => null,
            'common_column_names' => [],
            'search_query' => '',
            'search_query_input' => '',
            'search_hits' => [],
            'view' => 'diagram',
            'relations' => [
                [
                    'constraint_name' => 'FK_detail_header',
                    'schema' => 'dbo',
                    'parent_table' => 'tbl_store_document_detail',
                    'parent_column' => 'fld_store_document_title_id_ref',
                    'referenced_table' => 'tbl_store_document_titles',
                    'referenced_column' => 'fld_store_document_title_id',
                ],
            ],
            'relation_diagram_lines' => [
                'dbo.tbl_store_document_detail.fld_store_document_title_id_ref --> dbo.tbl_store_document_titles.fld_store_document_title_id',
            ],
        ]);

        $this->app->instance(SchemaExplorerService::class, $service);

        $response = $this->get('/reports/schema?view=diagram');

        $response->assertOk();
        $response->assertSee('Relation map (text diagram)');
        $response->assertSee('FK_detail_header');
        $response->assertSee('dbo.tbl_store_document_detail.fld_store_document_title_id_ref');
    }

    public function test_constraint_breakdown_tab_explains_selected_constraint(): void
    {
        $service = Mockery::mock(SchemaExplorerService::class);
        $service->shouldReceive('browse')->once()->andReturn([
            'tables' => [],
            'selected_table' => null,
            'columns' => [],
            'rows' => null,
            'common_column_names' => [],
            'search_query' => '',
            'search_query_input' => '',
            'search_hits' => [],
            'view' => 'constraint-breakdown',
            'relations' => [
                [
                    'constraint_name' => 'invoices_client_id_foreign',
                    'schema' => 'dbo',
                    'parent_table' => 'invoices',
                    'parent_column' => 'client_id',
                    'referenced_table' => 'clients',
                    'referenced_column' => 'id',
                ],
            ],
            'relation_diagram_lines' => [],
        ]);

        $this->app->instance(SchemaExplorerService::class, $service);

        $response = $this->get('/reports/schema?view=constraint-breakdown&constraint=invoices_client_id_foreign');

        $response->assertOk();
        $response->assertSee('Foreign key explanation');
        $response->assertSee('invoices_client_id_foreign');
        $response->assertSee('dbo.invoices.client_id');
        $response->assertSee('dbo.clients.id');
    }
}
