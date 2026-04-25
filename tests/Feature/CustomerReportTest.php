<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CustomerReportService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class CustomerReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_customer_report_page_renders_successfully(): void
    {
        $service = Mockery::mock(CustomerReportService::class);
        $service->shouldReceive('buildReport')->once()->andReturn([
            'table' => 'customers',
            'column_map' => ['name' => 'name'],
            'paginator' => new LengthAwarePaginator(
                items: [(object) ['customer_code' => 'C001', 'name' => 'Acme Corp']],
                total: 1,
                perPage: 20,
                currentPage: 1,
            ),
        ]);

        $this->app->instance(CustomerReportService::class, $service);

        $response = $this->get('/reports/customers');

        $response->assertStatus(200);
        $response->assertSee('Sales & customer reports');
        $response->assertSee('Acme Corp');
    }

    public function test_customer_report_data_endpoint_returns_json(): void
    {
        $service = Mockery::mock(CustomerReportService::class);
        $service->shouldReceive('buildReport')->once()->andReturn([
            'table' => 'customers',
            'column_map' => ['name' => 'name'],
            'paginator' => new LengthAwarePaginator(
                items: [(object) ['customer_code' => 'C001', 'name' => 'Acme Corp']],
                total: 1,
                perPage: 20,
                currentPage: 1,
            ),
        ]);

        $this->app->instance(CustomerReportService::class, $service);

        $response = $this->getJson('/reports/customers/data');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('table', 'customers')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_customer_report_validation_rejects_invalid_page_size(): void
    {
        $response = $this->get('/reports/customers?per_page=15');

        $response->assertStatus(302);
        $response->assertSessionHasErrors('per_page');
    }
}
