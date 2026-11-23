<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AccountingIndexRequest;
use App\Http\Requests\DeliveriesReceiptBookletAssignRequest;
use App\Http\Requests\DeliveriesReceiptBookletStoreRequest;
use App\Http\Requests\DeliveriesReceiptBookletUpdateRequest;
use App\Services\DeliveriesReceiptBookletSqliteService;
use App\Services\DeliveriesTeamSqliteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AccountingController extends Controller
{
    public function __construct(
        private readonly DeliveriesReceiptBookletSqliteService $receiptBooklets,
        private readonly DeliveriesTeamSqliteService $teams
    ) {}

    public function index(AccountingIndexRequest $request): View|RedirectResponse
    {
        $tab = (string) $request->query('tab', 'receipts');
        if ($tab !== 'receipts') {
            return redirect()->route('reports.accounting.index', ['tab' => 'receipts']);
        }

        $receiptBookletsAssigned = [];
        $receiptBookletsUnassigned = [];
        $receiptBookletsReturned = [];
        $drivers = [];

        try {
            $receiptBookletsAssigned = $this->receiptBooklets->listAssignedActive();
            $receiptBookletsUnassigned = $this->receiptBooklets->listUnassigned();
            $receiptBookletsReturned = $this->receiptBooklets->listReturned();
            $drivers = $this->teams->listDrivers();
        } catch (Throwable $e) {
            Log::warning('Accounting receipts data unavailable.', ['message' => $e->getMessage()]);
        }

        return view('reports.accounting.index', [
            'filters' => $request->filters(),
            'receiptBookletsAssigned' => $receiptBookletsAssigned,
            'receiptBookletsUnassigned' => $receiptBookletsUnassigned,
            'receiptBookletsReturned' => $receiptBookletsReturned,
            'drivers' => $drivers,
        ]);
    }

    public function storeReceiptBooklets(DeliveriesReceiptBookletStoreRequest $request): RedirectResponse
    {
        try {
            $result = $this->receiptBooklets->addBookletsFromRange(
                (int) $request->validated('first_number'),
                (int) $request->validated('last_number')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->receiptsRedirect()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklets_store_failed', ['message' => $e->getMessage()]);

            return $this->receiptsRedirect()->with('error', 'Could not add receipt booklets.');
        }

        $message = 'Added '.$result['added'].' receipt booklet(s).';
        if (($result['skipped'] ?? 0) > 0) {
            $message .= ' Skipped '.$result['skipped'].' duplicate booklet(s).';
        }

        return $this->receiptsRedirect()->with('status', $message);
    }

    public function assignReceiptBooklet(DeliveriesReceiptBookletAssignRequest $request): RedirectResponse
    {
        try {
            $this->receiptBooklets->assignByStartNumber(
                (int) $request->validated('start_number'),
                (string) $request->validated('driver_name')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->receiptsRedirect()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklet_assign_failed', ['message' => $e->getMessage()]);

            return $this->receiptsRedirect()->with('error', 'Could not assign receipt booklet.');
        }

        return $this->receiptsRedirect()->with('status', 'Receipt booklet assigned.');
    }

    public function returnReceiptBooklet(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'booklet_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->receiptBooklets->markReturned((int) $validated['booklet_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->receiptsRedirect()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklet_return_failed', ['message' => $e->getMessage()]);

            return $this->receiptsRedirect()->with('error', 'Could not mark receipt booklet as returned.');
        }

        return $this->receiptsRedirect()->with('status', 'Receipt booklet marked as returned.');
    }

    public function updateReceiptBooklet(DeliveriesReceiptBookletUpdateRequest $request, int $booklet): RedirectResponse
    {
        $validated = $request->validated();
        $input = [];

        if (array_key_exists('start_number', $validated) && $validated['start_number'] !== null) {
            $input['start_number'] = (int) $validated['start_number'];
        }
        if (array_key_exists('end_number', $validated) && $validated['end_number'] !== null) {
            $input['end_number'] = (int) $validated['end_number'];
        }
        if (array_key_exists('driver_name', $validated)) {
            $input['driver_name'] = $validated['driver_name'];
        }
        if (! empty($validated['unassign'])) {
            $input['unassign'] = true;
        }
        if (! empty($validated['undo_return'])) {
            $input['undo_return'] = true;
        }

        try {
            $this->receiptBooklets->updateBooklet($booklet, $input);
        } catch (\InvalidArgumentException $e) {
            return $this->receiptsRedirect()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklet_update_failed', ['message' => $e->getMessage()]);

            return $this->receiptsRedirect()->with('error', 'Could not update receipt booklet.');
        }

        return $this->receiptsRedirect()->with('status', 'Receipt booklet updated.');
    }

    public function destroyReceiptBooklet(Request $request, int $booklet): RedirectResponse
    {
        try {
            $this->receiptBooklets->deleteBooklet($booklet);
        } catch (\InvalidArgumentException $e) {
            return $this->receiptsRedirect()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklet_delete_failed', ['message' => $e->getMessage()]);

            return $this->receiptsRedirect()->with('error', 'Could not delete receipt booklet.');
        }

        return $this->receiptsRedirect()->with('status', 'Receipt booklet deleted.');
    }

    private function receiptsRedirect(): RedirectResponse
    {
        return redirect()->route('reports.accounting.index', ['tab' => 'receipts']);
    }
}
