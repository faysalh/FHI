<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PdaQuestionnaireStoreRequest;
use App\Http\Requests\PdaVisitEndRequest;
use App\Http\Requests\PdaVoucherRequest;
use App\Services\PdaWorkspaceService;
use App\Support\ReportAuthSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PdaController extends Controller
{
    private ?string $readError = null;

    public function __construct(private readonly PdaWorkspaceService $pda) {}

    public function index(Request $request): View
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        $search = trim((string) $request->query('q', ''));

        return view('reports.pda.index', $this->page($salesman, [
            'search' => $search,
            'customers' => $this->guarded(fn (): array => $this->pda->customers($salesman['user_id'], $search), []),
            'customerCount' => $this->guarded(fn (): int => $this->pda->customerCount($salesman['user_id']), 0),
        ]));
    }

    public function customer(string $customer): View|RedirectResponse
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        $row = $this->pda->customer($salesman['user_id'], $customer);
        if ($row === null) {
            return redirect()->route('reports.pda.index')->withErrors(['pda' => 'That customer is not on your list.']);
        }

        return view('reports.pda.customer', $this->page($salesman, [
            'customer' => $row,
            'invoices' => $this->guarded(fn (): array => $this->pda->invoices($row['id']), []),
            'visit' => $this->guarded(fn (): ?array => $this->pda->openVisit($salesman['user_id'], $row['id']), null),
        ]));
    }

    public function startVisit(Request $request): RedirectResponse
    {
        $salesman = $this->requireSalesman();
        $customerId = strtoupper(trim((string) $request->input('customer_id', '')));
        $customer = $this->pda->customer($salesman['user_id'], $customerId);
        if ($customer === null) {
            return back()->withErrors(['pda' => 'That customer is not on your list.']);
        }

        try {
            $visit = $this->pda->startVisit($salesman, $customer);
        } catch (RuntimeException $e) {
            return back()->withErrors(['pda' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('pda.visit_start_failed', ['message' => $e->getMessage()]);

            return back()->withErrors(['pda' => 'Could not start the visit.']);
        }

        $next = (string) $request->input('next', '');
        if (in_array($next, ['sale', 'order', 'return', 'receipt'], true)) {
            return redirect()->route('reports.pda.vouchers.create', [
                'visit' => $visit['visit_id'],
                'type' => $next,
            ]);
        }

        return redirect()->route('reports.pda.visits.show', ['visit' => $visit['visit_id']]);
    }

    public function showVisit(string $visit): View|RedirectResponse
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        $context = $this->visitContext($salesman, $visit);
        if ($context instanceof RedirectResponse) {
            return $context;
        }

        return view('reports.pda.visit', $this->page($salesman, [
            'visitId' => $context['visit_id'],
            'customer' => $context['customer'],
            'reasons' => $this->guarded(fn (): array => $this->pda->visitReasons(), []),
            'forms' => $this->guarded(fn (): array => $this->pda->questionnaireCatalog($salesman['user_id'])['forms'], []),
        ]));
    }

    public function endVisit(PdaVisitEndRequest $request, string $visit): RedirectResponse
    {
        $salesman = $this->requireSalesman();
        $input = $request->validated();
        try {
            $this->pda->endVisit(
                $salesman,
                $visit,
                (bool) $input['failed'],
                isset($input['reason_id']) ? (int) $input['reason_id'] : null,
                (string) ($input['note'] ?? ''),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['pda' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('pda.visit_end_failed', ['message' => $e->getMessage()]);

            return back()->withErrors(['pda' => 'Could not finish the visit.']);
        }

        return redirect()->route('reports.pda.index')->with('status', 'Visit finished.');
    }

    public function voucherForm(string $visit, Request $request): View|RedirectResponse
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        $kind = (string) $request->query('type', 'sale');
        if (! in_array($kind, ['sale', 'order', 'return', 'receipt'], true)) {
            $kind = 'sale';
        }
        $context = $this->visitContext($salesman, $visit);
        if ($context instanceof RedirectResponse) {
            return $context;
        }

        $items = [];
        if ($kind !== 'receipt') {
            $items = $this->guarded(
                fn (): array => array_values($this->pda->items($salesman['user_id'], '', 0)),
                []
            );
        }

        return view('reports.pda.voucher', $this->page($salesman, [
            'visitId' => $context['visit_id'],
            'customer' => $context['customer'],
            'kind' => $kind,
            'items' => $items,
            'allowZeroStock' => ReportAuthSession::canAccessReport('pda-zero-stock'),
        ]));
    }

    public function storeVoucher(PdaVoucherRequest $request, string $visit): RedirectResponse
    {
        $salesman = $this->requireSalesman();
        $context = $this->visitContext($salesman, $visit);
        if ($context instanceof RedirectResponse) {
            return $context;
        }

        $input = $request->validated();
        try {
            $this->pda->saveVoucher(
                $salesman,
                $context['customer'],
                $context['visit_id'],
                (string) $input['kind'],
                (float) ($input['paid'] ?? 0),
                (float) ($input['discount'] ?? 0),
                (string) ($input['notes'] ?? ''),
                (float) $input['lat'],
                (float) $input['lng'],
                isset($input['accuracy']) ? (float) $input['accuracy'] : null,
                array_values($input['lines'] ?? []),
                ReportAuthSession::canAccessReport('pda-zero-stock'),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['pda' => $e->getMessage()]);
        }

        return redirect()
            ->route('reports.pda.visits.show', ['visit' => $context['visit_id']])
            ->with('status', 'Voucher saved. It stays unposted until PDA sync runs.');
    }

    public function items(Request $request): View
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        $search = trim((string) $request->query('q', ''));

        return view('reports.pda.items', $this->page($salesman, [
            'search' => $search,
            'items' => $this->guarded(fn (): array => $this->pda->items($salesman['user_id'], $search), []),
        ]));
    }

    public function searchItems(Request $request): JsonResponse
    {
        $salesman = $this->requireSalesman();
        $search = trim((string) $request->query('q', ''));
        $items = $search === '' ? [] : $this->pda->items($salesman['user_id'], $search);
        $priceGroup = max(1, min(5, (int) $request->query('price_group', 1)));
        $out = [];
        foreach (array_slice($items, 0, 25) as $item) {
            $units = [];
            foreach ($item['units'] as $unit) {
                $units[] = [
                    'unit_row_id' => $unit['unit_row_id'],
                    'name' => $unit['name'],
                    'scale' => $unit['scale'],
                    'price' => $unit['prices'][$priceGroup] ?? 0,
                    'discount' => $unit['discount'],
                ];
            }
            $out[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'code' => $item['code'],
                'balance' => $item['balance'],
                'units' => $units,
            ];
        }

        return response()->json($out);
    }

    public function itemImage(string $item): BinaryFileResponse
    {
        $salesman = $this->requireSalesman();
        $row = $this->pda->item($salesman['user_id'], $item);
        if ($row === null || empty($row['has_image'])) {
            abort(404);
        }

        $path = $this->pda->itemThumbnail($item);
        if ($path === null) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function showItem(string $item): View|RedirectResponse
    {
        if (! ReportAuthSession::canAccessReport('pda-item-detail')) {
            abort(403, 'You do not have access to item prices and store stock.');
        }

        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        $row = $this->pda->item($salesman['user_id'], $item);
        if ($row === null) {
            return redirect()->route('reports.pda.items')->withErrors(['pda' => 'That item is not on your list.']);
        }

        return view('reports.pda.item', $this->page($salesman, [
            'item' => $row,
            'balances' => $this->guarded(fn (): array => $this->pda->itemBalances($row['id']), []),
        ]));
    }

    public function targets(): View
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        return view('reports.pda.targets', $this->page($salesman, [
            'targets' => $this->guarded(fn (): array => $this->pda->targets($salesman['user_id']), []),
        ]));
    }

    public function locations(): View
    {
        if (! ReportAuthSession::canAccessReport('pda-locations')) {
            abort(403, 'You do not have access to salesman locations.');
        }

        $userId = ReportAuthSession::userId();
        if ($userId === null) {
            abort(403);
        }

        $salesman = $this->pda->linkedSalesman($userId) ?? [
            'user_id' => '',
            'account_id' => '',
            'name' => '',
        ];

        return view('reports.pda.locations', $this->page($salesman, [
            'points' => $this->guarded(fn (): array => $this->pda->lastLocations(), []),
            'stored' => $this->guarded(fn (): array => $this->pda->storedLocations(null, true), []),
            'showAll' => true,
        ]));
    }

    public function questionnaires(): View
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        $catalog = $this->guarded(fn (): array => $this->pda->questionnaireCatalog($salesman['user_id']), ['forms' => []]);

        return view('reports.pda.questionnaires', $this->page($salesman, [
            'forms' => $catalog['forms'] ?? [],
        ]));
    }

    public function questionnaire(Request $request, string $questionnaire): View|RedirectResponse
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        $catalog = $this->pda->questionnaireCatalog($salesman['user_id']);
        $form = null;
        foreach ($catalog['forms'] as $candidate) {
            if ($candidate['id'] === strtoupper($questionnaire)) {
                $form = $candidate;
                break;
            }
        }
        if ($form === null) {
            return redirect()->route('reports.pda.questionnaires')->withErrors(['pda' => 'That questionnaire is not available.']);
        }

        $questions = array_values(array_filter(
            $catalog['questions'],
            static fn (array $question): bool => $question['form_id'] === $form['id']
        ));
        $customerId = strtoupper(trim((string) $request->query('customer', '')));
        $customer = $customerId !== '' ? $this->pda->customer($salesman['user_id'], $customerId) : null;
        $results = $this->guarded(fn (): array => $this->pda->questionnaireResults($form['id']), []);

        return view('reports.pda.questionnaire', $this->page($salesman, [
            'form' => $form,
            'questions' => $questions,
            'sources' => $catalog['sources'],
            'customer' => $customer,
            'visitId' => strtoupper(trim((string) $request->query('visit', ''))),
            'results' => $results,
        ]));
    }

    public function storeQuestionnaire(PdaQuestionnaireStoreRequest $request, string $questionnaire): RedirectResponse
    {
        $salesman = $this->requireSalesman();
        $input = $request->validated();
        try {
            $this->pda->saveQuestionnaire(
                $salesman,
                (string) $input['customer_id'],
                $questionnaire,
                is_array($input['answers'] ?? null) ? $input['answers'] : [],
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['pda' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('pda.questionnaire_failed', ['message' => $e->getMessage()]);

            return back()->withInput()->withErrors(['pda' => 'Could not save the questionnaire.']);
        }

        $visitId = strtoupper(trim((string) ($input['visit_id'] ?? '')));
        if ($visitId !== '') {
            return redirect()->route('reports.pda.visits.show', ['visit' => $visitId])->with('status', 'Questionnaire saved.');
        }

        return redirect()->route('reports.pda.questionnaires.show', ['questionnaire' => strtoupper($questionnaire)])->with('status', 'Questionnaire saved.');
    }

    public function unposted(): View
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            return $salesman;
        }

        return view('reports.pda.unposted', $this->page($salesman, [
            'vouchers' => $this->guarded(fn (): array => $this->pda->unposted($salesman['user_id']), []),
        ]));
    }

    /**
     * @param  array{user_id: string, account_id: string, name: string}  $salesman
     * @return array{visit_id: string, customer: array<string, mixed>}|RedirectResponse
     */
    private function visitContext(array $salesman, string $visitId): array|RedirectResponse
    {
        $visitId = strtoupper(trim($visitId));
        try {
            $row = \Illuminate\Support\Facades\DB::connection('sqlsrv')->selectOne(
                'SELECT TOP 1 CAST(fld_customer_id_ref AS nvarchar(36)) AS customer_id, fld_end_date
                 FROM dbo.tbl_pda_visits
                 WHERE fld_visit_id = ? AND fld_user_id_ref = ?',
                [$visitId, $salesman['user_id']]
            );
        } catch (Throwable $e) {
            Log::error('pda.visit_load_failed', ['message' => $e->getMessage()]);

            return redirect()->route('reports.pda.index')->withErrors(['pda' => 'Could not open the visit.']);
        }

        if ($row === null) {
            return redirect()->route('reports.pda.index')->withErrors(['pda' => 'This visit was not found.']);
        }
        if ($row->fld_end_date !== null && (string) $row->fld_end_date !== '') {
            return redirect()->route('reports.pda.index')->withErrors(['pda' => 'This visit is already finished.']);
        }

        $customer = $this->pda->customer($salesman['user_id'], (string) $row->customer_id);
        if ($customer === null) {
            return redirect()->route('reports.pda.index')->withErrors(['pda' => 'The customer for this visit is not on your list.']);
        }

        return ['visit_id' => $visitId, 'customer' => $customer];
    }

    /**
     * @return array{user_id: string, account_id: string, name: string}|View
     */
    private function salesman(): array|View
    {
        $userId = ReportAuthSession::userId();
        if ($userId === null) {
            abort(403);
        }

        $salesman = $this->pda->linkedSalesman($userId);
        if ($salesman === null) {
            return view('reports.pda.unlinked');
        }

        return $salesman;
    }

    /**
     * @return array{user_id: string, account_id: string, name: string}
     */
    private function requireSalesman(): array
    {
        $salesman = $this->salesman();
        if ($salesman instanceof View) {
            abort(403, 'Link this login to a salesman before using PDA.');
        }

        return $salesman;
    }

    /**
     * @param  array{user_id: string, account_id: string, name: string}  $salesman
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function page(array $salesman, array $extra = []): array
    {
        view()->share('pdaSalesmanName', $salesman['name']);

        return array_merge([
            'salesman' => $salesman,
            'companyName' => $this->pda->companyName($salesman['user_id']),
            'readError' => $this->readError,
        ], $extra);
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    private function guarded(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::warning('pda.read_failed', ['message' => $e->getMessage()]);
            $this->readError = 'Could not load PDA data. Try again in a moment.';

            return $fallback;
        }
    }
}
