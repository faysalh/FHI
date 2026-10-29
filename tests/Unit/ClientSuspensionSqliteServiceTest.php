<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClientSuspensionSqliteService;
use App\Services\PdaLocationSqliteService;
use App\Services\PdaWorkspaceService;
use App\Services\ReportsUsersSqliteService;
use App\Support\ClientSuspensionNotice;
use App\Support\ReportNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class ClientSuspensionSqliteServiceTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = storage_path('app/test-client-suspensions-'.uniqid('', true).'.sqlite');
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

    public function test_suspend_release_and_id_check(): void
    {
        $service = new ClientSuspensionSqliteService;
        $id = '7C9C3F58-DB0B-4397-A635-FAFF0FDB1859';

        $this->assertFalse($service->isSuspended($id));

        $service->suspend($id, 'C-10', 'Ahmad Shop', 'Karwan', 'finance');

        $this->assertTrue($service->isSuspended(strtolower($id)));
        $this->assertArrayHasKey($id, $service->suspendedIdSet());

        $rows = $service->list('ahmad');
        $this->assertCount(1, $rows);
        $this->assertSame('Ahmad Shop', $rows[0]->client_name);
        $this->assertSame('Karwan', $rows[0]->salesman_name);
        $this->assertSame([], $service->list('missing'));

        $this->assertTrue($service->release('{'.$id.'}'));
        $this->assertFalse($service->isSuspended($id));
        $this->assertSame([], $service->list());
    }

    public function test_navigation_includes_suspensions_under_finance(): void
    {
        $this->assertContains('client-suspensions', ReportNavigation::assignablePermissionKeys());
        $this->assertSame('client-suspensions', ReportNavigation::activeKey('reports.client-suspensions.index'));

        $labels = [];
        foreach (ReportNavigation::sectionsForUser(['client-suspensions'], false) as $section) {
            if ($section['label'] !== 'Finance') {
                continue;
            }
            foreach ($section['items'] as $item) {
                $labels[] = $item['label'];
            }
        }

        $this->assertSame(['Suspensions'], $labels);

        $finance = [];
        foreach (ReportNavigation::sectionsForUser([
            'accounting',
            'client-balance',
            'general-ledger',
            'promotions',
            'client-suspensions',
        ], false) as $section) {
            if ($section['label'] !== 'Finance') {
                continue;
            }
            foreach ($section['items'] as $item) {
                $finance[] = $item['label'];
            }
        }

        $this->assertSame(
            ['Receipts', 'Client balance', 'General ledger', 'Promotions', 'Suspensions'],
            $finance
        );
    }

    public function test_save_is_refused_for_a_suspended_client(): void
    {
        $suspensions = new ClientSuspensionSqliteService;
        $id = 'A699007E-6B3C-4E10-AE03-5BA91B2D31B0';
        $suspensions->suspend($id, 'C-2', 'Late Shop', 'Saman', 'finance');

        $pda = new PdaWorkspaceService(
            new ReportsUsersSqliteService,
            new PdaLocationSqliteService,
            $suspensions,
        );

        $this->assertSame(ClientSuspensionNotice::plain(), $pda->suspensionBlockMessage($id));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ClientSuspensionNotice::ARABIC);
        $pda->saveVoucher(
            ['user_id' => '11111111-1111-1111-1111-111111111111', 'account_id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Ali'],
            ['id' => $id, 'price_group' => 1],
            '33333333-3333-3333-3333-333333333333',
            'sale',
            0,
            0,
            '',
            36.2,
            44.0,
            null,
            [],
        );
    }
}
