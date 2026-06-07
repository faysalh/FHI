<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\InvoicesReportRepository;
use App\Repositories\VisitsReportRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class InvoicesReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_invoices_page_renders(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'invoice_id' => 'inv-1',
                'invoice_no' => '1001',
                'invoice_date' => '2026-04-20',
                'last_print_date' => '2026-04-20 12:30:00',
                'created_at' => '2026-04-20 10:00:00',
                'client_code' => 'C-100',
                'client_name' => 'Client One',
                'city_name' => 'Erbil',
                'store_name' => 'Main Store',
                'salesman_name' => 'Salesman A',
                'quantity_total' => 15,
                'invoice_amount' => 250000,
                'client_due_amount' => 100000,
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(InvoicesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getStoreOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')->zeroOrMoreTimes()->andReturn((object) [
            'quantity_total' => 15,
            'invoice_amount' => 250000,
            'client_due_amount' => 100000,
        ]);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->once()->andReturn(['Erbil']);
        $visits->shouldReceive('getSalesmanOptions')->once()->andReturn([
            ['id' => 'sm-1', 'name' => 'Salesman A'],
        ]);

        $this->app->instance(InvoicesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->get('/reports/invoices?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertSee('Invoices report');
        $response->assertSee('Client One');
        $response->assertSee('1001');
        $response->assertSee('Last print date');
        $response->assertSee('2026-04-20 12:30:00');
    }

    public function test_invoices_print_links_include_picked_flag(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [(object) [
                'invoice_id' => 'inv-reset',
                'invoice_no' => '1002',
                'invoice_date' => '2026-04-20',
                'last_print_date' => null,
                'created_at' => '2026-04-20 10:00:00',
                'client_code' => 'C-100',
                'client_name' => 'Client One',
                'city_name' => 'Erbil',
                'store_name' => 'Main Store',
                'salesman_name' => 'Salesman A',
                'quantity_total' => 15,
                'invoice_amount' => 250000,
                'client_due_amount' => 100000,
            ]],
            total: 1,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(InvoicesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getStoreOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')->zeroOrMoreTimes()->andReturn((object) [
            'quantity_total' => 15,
            'invoice_amount' => 250000,
            'client_due_amount' => 100000,
        ]);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->once()->andReturn(['Erbil']);
        $visits->shouldReceive('getSalesmanOptions')->once()->andReturn([
            ['id' => 'sm-1', 'name' => 'Salesman A'],
        ]);

        $this->app->instance(InvoicesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->get('/reports/invoices?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertSee('picked=0');
    }

    public function test_invoices_index_orders_unpicked_first_then_invoice_number_ascending(): void
    {
        Cache::forever('reports.invoices.selection.v1', [
            'inv-picked' => true,
        ]);

        $paginator = new LengthAwarePaginator(
            items: [
                (object) [
                    'invoice_id' => 'inv-picked',
                    'invoice_no' => '10',
                    'invoice_date' => '2026-04-20',
                    'last_print_date' => null,
                    'created_at' => '2026-04-20 10:00:00',
                    'client_code' => 'C-100',
                    'client_name' => 'Picked Client',
                    'city_name' => 'Erbil',
                    'store_name' => 'Main Store',
                    'salesman_name' => 'Salesman A',
                    'quantity_total' => 15,
                    'invoice_amount' => 250000,
                    'client_due_amount' => 100000,
                ],
                (object) [
                    'invoice_id' => 'inv-unpicked-20',
                    'invoice_no' => '20',
                    'invoice_date' => '2026-04-20',
                    'last_print_date' => null,
                    'created_at' => '2026-04-20 10:00:00',
                    'client_code' => 'C-101',
                    'client_name' => 'Unpicked Client 20',
                    'city_name' => 'Erbil',
                    'store_name' => 'Main Store',
                    'salesman_name' => 'Salesman A',
                    'quantity_total' => 15,
                    'invoice_amount' => 250000,
                    'client_due_amount' => 100000,
                ],
                (object) [
                    'invoice_id' => 'inv-unpicked-2',
                    'invoice_no' => '2',
                    'invoice_date' => '2026-04-20',
                    'last_print_date' => null,
                    'created_at' => '2026-04-20 10:00:00',
                    'client_code' => 'C-102',
                    'client_name' => 'Unpicked Client 2',
                    'city_name' => 'Erbil',
                    'store_name' => 'Main Store',
                    'salesman_name' => 'Salesman A',
                    'quantity_total' => 15,
                    'invoice_amount' => 250000,
                    'client_due_amount' => 100000,
                ],
            ],
            total: 3,
            perPage: 25,
            currentPage: 1
        );

        $repo = Mockery::mock(InvoicesReportRepository::class);
        $repo->shouldReceive('normalizeCities')->andReturn([]);
        $repo->shouldReceive('getStoreOptions')->once()->andReturn(['Main Store']);
        $repo->shouldReceive('getReport')->once()->andReturn($paginator);
        $repo->shouldReceive('getReportTotals')->zeroOrMoreTimes()->andReturn((object) [
            'quantity_total' => 15,
            'invoice_amount' => 250000,
            'client_due_amount' => 100000,
        ]);

        $visits = Mockery::mock(VisitsReportRepository::class);
        $visits->shouldReceive('getCityOptions')->once()->andReturn(['Erbil']);
        $visits->shouldReceive('getSalesmanOptions')->once()->andReturn([
            ['id' => 'sm-1', 'name' => 'Salesman A'],
        ]);

        $this->app->instance(InvoicesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->get('/reports/invoices?date_from=2026-04-01&date_to=2026-04-20');

        $response->assertOk();
        $response->assertSeeInOrder([
            'Unpicked Client 2',
            'Unpicked Client 20',
            'Picked Client',
        ]);
    }

    public function test_invoices_items_endpoint_returns_json(): void
    {
        $repo = Mockery::mock(InvoicesReportRepository::class);
        $repo->shouldReceive('getInvoiceItems')->once()->andReturn([
            (object) [
                'item_name' => 'Wings',
                'quantity' => 5,
                'amount' => 20000,
            ],
        ]);

        $this->app->instance(InvoicesReportRepository::class, $repo);

        $response = $this->get('/reports/invoices/items?invoice_id=inv-1');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('rows.0.item_name', 'Wings');
    }

    public function test_invoice_selection_endpoint_persists_state(): void
    {
        $repo = Mockery::mock(InvoicesReportRepository::class);
        $visits = Mockery::mock(VisitsReportRepository::class);
        $this->app->instance(InvoicesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->postJson('/reports/invoices/selection', [
            'invoice_id' => 'inv-1',
            'selected' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
    }

    public function test_invoice_print_includes_pdf_footer_details(): void
    {
        $repo = Mockery::mock(InvoicesReportRepository::class);
        $repo->shouldReceive('getInvoiceHeader')->twice()->with('inv-1')->andReturn((object) [
            'invoice_id' => 'inv-1',
            'invoice_no' => '1001',
            'invoice_date' => '2026-04-20',
            'last_print_date' => '2026-04-20 11:00:00',
            'created_at' => '2026-04-20 10:00:00',
            'client_code' => 'C-100',
            'client_name' => 'Client One',
            'client_phone' => '07500000000',
            'client_address' => 'Main Street',
            'city_name' => 'Duhok',
            'store_name' => 'Main Store',
            'salesman_name' => 'Salesman A',
            'salesman_phone' => '07501111111',
            'quantity_total' => 5,
            'invoice_amount' => 20000,
            'client_due_amount' => 100000,
            'invoice_desc' => 'Deliver before noon',
        ]);
        $repo->shouldReceive('getInvoiceItems')->once()->with('inv-1')->andReturn([
            (object) [
                'item_code' => 'IT-1',
                'item_name' => 'Wings',
                'quantity' => 5,
                'amount' => 20000,
            ],
        ]);
        $repo->shouldReceive('touchLastPrintDate')->once()->with('inv-1', false)->andReturn(0);

        $visits = Mockery::mock(VisitsReportRepository::class);

        $this->app->instance(InvoicesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->get('/reports/invoices/print?invoice_id=inv-1');

        $response->assertOk();
        $response->assertSee('Deliver before noon');
        $response->assertSee('وصف الفاتورة');
        $response->assertSee('مجموع الفاتورة');
        $response->assertSee('الرصيد السابق');
        $response->assertSee('المجموع الكلي');
        $response->assertSee('120,000');
        $response->assertSee('خصم / discount %');
    }

    public function test_invoice_first_print_can_mark_delivery_not_delivered(): void
    {
        $repo = Mockery::mock(InvoicesReportRepository::class);
        $repo->shouldReceive('getInvoiceHeader')->twice()->with('inv-reset')->andReturn(
            (object) [
                'invoice_id' => 'inv-reset',
                'invoice_no' => '1001',
                'invoice_date' => '2026-04-20',
                'last_print_date' => null,
                'created_at' => '2026-04-20 10:00:00',
                'client_code' => 'C-100',
                'client_name' => 'Client One',
                'client_phone' => '07500000000',
                'client_address' => 'Main Street',
                'city_name' => 'Duhok',
                'store_name' => 'Main Store',
                'salesman_name' => 'Salesman A',
                'salesman_phone' => '07501111111',
                'quantity_total' => 5,
                'invoice_amount' => 20000,
                'client_due_amount' => 100000,
                'invoice_desc' => '',
            ],
            (object) [
                'invoice_id' => 'inv-reset',
                'invoice_no' => '1001',
                'invoice_date' => '2026-04-20',
                'last_print_date' => '2026-04-27 15:00:00',
                'created_at' => '2026-04-20 10:00:00',
                'client_code' => 'C-100',
                'client_name' => 'Client One',
                'client_phone' => '07500000000',
                'client_address' => 'Main Street',
                'city_name' => 'Duhok',
                'store_name' => 'Main Store',
                'salesman_name' => 'Salesman A',
                'salesman_phone' => '07501111111',
                'quantity_total' => 5,
                'invoice_amount' => 20000,
                'client_due_amount' => 100000,
                'invoice_desc' => '',
            ]
        );
        $repo->shouldReceive('getInvoiceItems')->once()->with('inv-reset')->andReturn([
            (object) [
                'item_code' => 'IT-1',
                'item_name' => 'Wings',
                'quantity' => 5,
                'amount' => 20000,
            ],
        ]);
        $repo->shouldReceive('touchLastPrintDate')->once()->with('inv-reset', true)->andReturn(5);

        $visits = Mockery::mock(VisitsReportRepository::class);

        $this->app->instance(InvoicesReportRepository::class, $repo);
        $this->app->instance(VisitsReportRepository::class, $visits);

        $response = $this->get('/reports/invoices/print?invoice_id=inv-reset&picked=0');

        $response->assertOk();
        $response->assertSee('1001');
    }

    public function test_invoice_pdf_template_uses_arabic_direction_settings(): void
    {
        $html = view('reports.invoices.pdf', [
            'invoice' => (object) [
                'invoice_id' => 'inv-1',
                'invoice_no' => '1001',
                'invoice_date' => '2026-04-20',
                'created_at' => '2026-04-20 10:00:00',
                'client_code' => 'C-100',
                'client_name' => 'ماركيت هةوري ريكاي كؤية',
                'client_phone' => '07500000000',
                'client_address' => 'كوية',
                'city_name' => 'Duhok',
                'store_name' => 'Main Store',
                'salesman_name' => 'Salesman A',
                'salesman_phone' => '07501111111',
                'quantity_total' => 5,
                'invoice_amount' => 20000,
                'client_due_amount' => 100000,
                'invoice_desc' => 'ملاحظة',
            ],
            'items' => [
                (object) [
                    'item_code' => 'IT-1',
                    'item_name' => 'جناح',
                    'quantity' => 5,
                    'amount' => 20000,
                ],
            ],
            'branding' => [
                'company_name' => 'شركة الاختبار',
                'company_mobile' => '07500000000',
                'company_address' => 'العنوان',
                'footer_note' => 'ملاحظة الفوتر',
                'invoice_direction' => 'rtl',
            ],
            'brandingLogoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('lang="ar" dir="rtl"', $html);
        $this->assertStringContainsString('direction: rtl', $html);
        $this->assertStringContainsString('.items { width: 100%; border-collapse: collapse; margin-top: 8px; table-layout: auto; direction: rtl; }', $html);
        $this->assertStringContainsString('<tr><td class="value">1001</td><td class="label">', $html);
        $this->assertStringContainsString('<tr><td class="num">20,000 د.ع</td><td class="label">', $html);
        $this->assertStringContainsString('<td class="value">07500000000</td><td class="label">', $html);
        $this->assertStringContainsString('<th class="center col-idx">#</th>', $html);
        $this->assertStringContainsString('class="num col-discount"', $html);
        $this->assertMatchesRegularExpression('/<tr>\s*<td class="num col-total">\s*20,000 د\.ع\s*<\/td>\s*<td class="num col-discount">0%\s*<\/td>\s*<td class="num col-unit">4,000 د\.ع<\/td>\s*<td class="num col-qty">5<\/td>/u', $html);
        $this->assertMatchesRegularExpression('/<tfoot>.*?<tr>\s*<td class="num col-total">\s*20,000 د\.ع\s*<\/td>\s*<td class="col-discount"><\/td>\s*<td class="col-unit"><\/td>\s*<td class="num col-qty">5<\/td>/us', $html);
        $this->assertStringContainsString('د.ع', $html);
        $this->assertStringNotContainsString('Invoice Total', $html);
    }
}
