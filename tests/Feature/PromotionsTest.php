<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PromotionsScheduleService;
use App\Services\PromotionsSqliteService;
use App\Support\PromotionsWeekdays;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromotionsTest extends TestCase
{
    private PromotionsSqliteService $promotions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.promotions_sqlite.database', ':memory:');
        DB::purge('promotions_sqlite');
        $this->promotions = app(PromotionsSqliteService::class);
        $this->promotions->ensureReady();
    }

    /**
     * @param  list<string>  $allowedKeys
     */
    private function sessionWithPermissions(array $allowedKeys): self
    {
        return $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'promo-user',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => $allowedKeys,
        ]);
    }

    public function test_promotions_requires_permission(): void
    {
        $this->app['env'] = 'local';

        $response = $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 2,
            'reports_username' => 'sales-only',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['sales'],
        ])->get('/reports/promotions');

        $response->assertForbidden();
    }

    public function test_promoter_crud_and_assignment(): void
    {
        $response = $this->sessionWithPermissions(['promotions'])->get('/reports/promotions');
        $response->assertOk();
        $response->assertSee('Promotions');

        $this->sessionWithPermissions(['promotions'])->post('/reports/promotions/promoters', [
            'tab' => 'setup',
            'employee_name' => 'Ali Promoter',
            'vehicle' => 'ABC-123',
        ])->assertRedirect();

        $promoter = $this->promotions->listPromoters()[0];
        $this->assertSame('Ali Promoter', $promoter->employee_name);

        $this->sessionWithPermissions(['promotions'])->post('/reports/promotions/assignments', [
            'tab' => 'assignments',
            'promoter_id' => (int) $promoter->id,
            'client_account_id' => '1001',
            'client_name' => 'Market Alpha',
            'visit_days' => [1, 3],
        ])->assertRedirect();

        $assignments = $this->promotions->listAssignmentsForPromoter((int) $promoter->id);
        $this->assertCount(1, $assignments);
        $this->assertSame('Market Alpha', $assignments[0]->client_name);

        $effective = $this->promotions->effectiveVisitDays($promoter, $assignments[0]);
        $this->assertSame([1, 3], $effective);
    }

    public function test_promoter_can_be_added_with_name_and_vehicle_only(): void
    {
        $response = $this->sessionWithPermissions(['promotions'])->post('/reports/promotions/promoters', [
            'tab' => 'setup',
            'employee_name' => 'No Days Promoter',
            'vehicle' => 'Van 9',
        ]);

        $response->assertRedirect();
        $promoter = $this->promotions->listPromoters()[0];
        $this->assertSame('No Days Promoter', $promoter->employee_name);
        $this->assertSame('Van 9', $promoter->vehicle);
        $this->assertSame('[]', $promoter->default_visit_days);
    }

    public function test_schedule_lists_clients_under_weekdays(): void
    {
        $promoterId = $this->promotions->createPromoter('Sara', 'Van 1');
        $this->promotions->assignClient($promoterId, '2001', 'Shop One', [0, 2]);
        $this->promotions->assignClient($promoterId, '2002', 'Shop Two', [2]);

        $weekStart = PromotionsWeekdays::normalizeWeekStart('2026-06-07');
        $this->assertSame('2026-06-06', $weekStart);
        $this->assertSame('2026-06-11', PromotionsWeekdays::weekEndDate($weekStart));

        $sheet = app(PromotionsScheduleService::class)->buildSheetForPromoter($promoterId, $weekStart);

        $this->assertNotEmpty($sheet['columns']);
        $this->assertContains('Shop One', $sheet['cells'][0] ?? []);
        $this->assertContains('Shop Two', $sheet['cells'][2] ?? []);
        $this->assertNotContains(5, array_column($sheet['columns'], 'weekday'));

        $this->assertSame('2026-06-06', $sheet['week_start']);
        $this->assertSame('2026-06-11', $sheet['week_end']);
        $columnWeekdays = array_column($sheet['columns'], 'weekday');
        $this->assertSame(6, $columnWeekdays[0] ?? null);
        $this->assertSame(4, $columnWeekdays[count($columnWeekdays) - 1] ?? null);

        $response = $this->sessionWithPermissions(['promotions'])->get('/reports/promotions?tab=schedule&promoter_id='.$promoterId.'&week_start='.$weekStart);
        $response->assertOk();
        $response->assertSee('Shop One');
        $response->assertSee('Shop Two');
    }

    public function test_export_pdf_and_csv_for_single_promoter(): void
    {
        $promoterId = $this->promotions->createPromoter('Export Test', '');
        $this->promotions->assignClient($promoterId, '3001', 'Client X', [1]);
        $weekStart = PromotionsWeekdays::normalizeWeekStart('2026-06-07');

        $pdf = $this->sessionWithPermissions(['promotions'])->get('/reports/promotions/export/pdf?promoter_id='.$promoterId.'&week_start='.$weekStart);
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');

        $csv = $this->sessionWithPermissions(['promotions'])->get('/reports/promotions/export/csv?promoter_id='.$promoterId.'&week_start='.$weekStart);
        $csv->assertOk();
        $contentType = (string) $csv->headers->get('content-type');
        $this->assertTrue(
            str_contains($contentType, 'text/csv') || str_contains($contentType, 'text/plain'),
            'Expected CSV download content type, got: '.$contentType
        );
    }

    public function test_client_cannot_be_assigned_to_two_promoters(): void
    {
        $p1 = $this->promotions->createPromoter('P1', '');
        $p2 = $this->promotions->createPromoter('P2', '');
        $this->promotions->assignClient($p1, '4001', 'Shared Client', [1]);

        $this->sessionWithPermissions(['promotions'])->post('/reports/promotions/assignments', [
            'tab' => 'assignments',
            'promoter_id' => $p2,
            'client_account_id' => '4001',
            'client_name' => 'Shared Client',
            'visit_days' => [2],
        ])->assertRedirect()->assertSessionHas('error');
    }

    public function test_assignment_requires_one_to_three_visit_days(): void
    {
        $promoterId = $this->promotions->createPromoter('Validator', '');

        $this->sessionWithPermissions(['promotions'])->post('/reports/promotions/assignments', [
            'tab' => 'assignments',
            'promoter_id' => $promoterId,
            'client_account_id' => '5001',
            'client_name' => 'No Days Client',
            'visit_days' => [],
        ])->assertSessionHasErrors('visit_days');

        $this->sessionWithPermissions(['promotions'])->post('/reports/promotions/assignments', [
            'tab' => 'assignments',
            'promoter_id' => $promoterId,
            'client_account_id' => '5002',
            'client_name' => 'Too Many Days',
            'visit_days' => [0, 1, 2, 3],
        ])->assertSessionHasErrors('visit_days');
    }

    public function test_daily_visits_assigns_all_working_days(): void
    {
        $promoterId = $this->promotions->createPromoter('Daily', '');
        $this->promotions->assignClient($promoterId, '6001', 'Daily Shop', PromotionsWeekdays::allowedWeekdayNumbers());

        $assignment = $this->promotions->listAssignmentsForPromoter($promoterId)[0];
        $promoter = $this->promotions->getPromoter($promoterId);
        $days = $this->promotions->effectiveVisitDays($promoter, $assignment);

        $this->assertTrue(PromotionsWeekdays::isDailyVisitSchedule($days));
    }

    public function test_assignments_page_loads_without_sql_server_query(): void
    {
        $promoterId = $this->promotions->createPromoter('Local Only', '');

        $response = $this->sessionWithPermissions(['promotions'])->get(
            '/reports/promotions?tab=assignments&promoter_id='.$promoterId
        );

        $response->assertOk();
        $response->assertSee('Select 1 to 3 visit days');
        $response->assertDontSee('QueryException');
    }

    public function test_api_clients_returns_json_without_query(): void
    {
        $response = $this->sessionWithPermissions(['promotions'])->getJson('/reports/promotions/api/clients?q=');

        $response->assertOk();
        $response->assertJson(['ok' => true, 'rows' => []]);
    }

    public function test_weekday_normalization_excludes_friday(): void
    {
        $normalized = PromotionsWeekdays::normalizeList([1, 5, 3]);
        $this->assertSame([1, 3], $normalized);
    }
}
