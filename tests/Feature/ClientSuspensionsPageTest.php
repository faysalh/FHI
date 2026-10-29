<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ClientSuspensionSqliteService;
use App\Support\ClientSuspensionNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ClientSuspensionsPageTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = storage_path('app/test-client-suspensions-page-'.uniqid('', true).'.sqlite');
        config(['database.connections.client_suspensions_sqlite.database' => $this->dbPath]);
        DB::purge('client_suspensions_sqlite');
    }

    protected function tearDown(): void
    {
        DB::purge('client_suspensions_sqlite');
        if ($this->dbPath !== '' && File::exists($this->dbPath)) {
            File::delete($this->dbPath);
        }

        parent::tearDown();
    }

    public function test_page_lists_a_suspended_client_and_remove_clears_it(): void
    {
        $id = 'DE0C06D4-5B55-49F0-9B17-18ECF0577CC0';
        (new ClientSuspensionSqliteService)->suspend($id, 'C-9', 'Dukan Late', 'Hadi', 'finance');

        $page = $this->get('/reports/client-suspensions?q=Dukan');
        $page->assertOk();
        $page->assertSee('Suspensions');
        $page->assertSee('Dukan Late');
        $page->assertSee('Hadi');
        $page->assertSee('Export PDF');
        $page->assertSee('Export CSV');

        $csv = $this->get('/reports/client-suspensions/export/csv?q=Dukan');
        $csv->assertOk();
        $contentType = (string) $csv->headers->get('content-type');
        $this->assertTrue(
            str_contains($contentType, 'text/csv') || str_contains($contentType, 'text/plain'),
            'Expected CSV download content type, got: '.$contentType
        );
        $csvBody = method_exists($csv->baseResponse, 'getFile')
            ? (string) file_get_contents($csv->baseResponse->getFile()->getPathname())
            : $csv->streamedContent();
        $this->assertStringContainsString('Dukan Late', $csvBody);

        $this->delete('/reports/client-suspensions/'.$id.'?q=Dukan')->assertRedirect('/reports/client-suspensions?q=Dukan');
        $this->get('/reports/client-suspensions')->assertDontSee('Dukan Late');
    }

    public function test_pda_list_marks_a_suspended_client_and_does_not_link_it(): void
    {
        $html = view('reports.pda.index', [
            'salesman' => ['name' => 'Ali', 'user_id' => '1', 'account_id' => '2'],
            'companyName' => '',
            'search' => '',
            'customerCount' => 1,
            'customers' => [[
                'id' => '7C9C3F58-DB0B-4397-A635-FAFF0FDB1859',
                'name' => 'Blocked Shop',
                'code' => 'C-1',
                'phone' => '0750',
                'balance' => 10,
                'route' => 'North',
                'suspended' => true,
            ]],
        ])->render();

        $this->assertStringContainsString('pda-card--suspended', $html);
        $this->assertStringContainsString(ClientSuspensionNotice::ARABIC, $html);
        $this->assertStringContainsString(ClientSuspensionNotice::KURDISH, $html);
        $this->assertStringNotContainsString('reports/pda/customers/7C9C3F58-DB0B-4397-A635-FAFF0FDB1859', $html);
    }
}
