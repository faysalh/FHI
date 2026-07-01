<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\AccountingSummaryExport;
use App\Http\Requests\AccountingCashRowStoreRequest;
use App\Http\Requests\AccountingCashRowUpdateRequest;
use App\Http\Requests\AccountingCashSheetRequest;
use App\Http\Requests\AccountingIndexRequest;
use App\Http\Requests\AccountingSheetExportRequest;
use App\Http\Requests\AccountingSummaryExportRequest;
use App\Http\Requests\AccountingTransferRowStoreRequest;
use App\Http\Requests\AccountingTransferRowUpdateRequest;
use App\Http\Requests\DeliveriesReceiptBookletAssignRequest;
use App\Http\Requests\DeliveriesReceiptBookletStoreRequest;
use App\Http\Requests\DeliveriesReceiptBookletUpdateRequest;
use App\Services\AccountingSqliteService;
use App\Services\DeliveriesReceiptBookletSqliteService;
use App\Services\DeliveriesTeamSqliteService;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AccountingController extends Controller
{
    public function __construct(
        private readonly AccountingSqliteService $accounting,
        private readonly DeliveriesReceiptBookletSqliteService $receiptBooklets,
        private readonly DeliveriesTeamSqliteService $teams
    ) {}

    public function index(AccountingIndexRequest $request): View
    {
        $filters = $request->filters();
        $tab = $filters['tab'];
        $selectedDate = $filters['date'];

        $cashBundle = ['sheet' => null, 'spent' => 0.0, 'remaining' => 0.0, 'rows' => []];
        $transferRows = [];
        $receiptBookletsAssigned = [];
        $receiptBookletsUnassigned = [];
        $receiptBookletsReturned = [];
        $cashSummary = [];
        $transferSummary = [];
        $drivers = [];

        try {
            if ($tab === 'cash') {
                $cashBundle = $this->accounting->cashSheetBundle($selectedDate);
            } elseif ($tab === 'transfers') {
                $transferRows = $this->accounting->listTransferRowsForDate($selectedDate);
            } elseif ($tab === 'receipts') {
                $receiptBookletsAssigned = $this->receiptBooklets->listAssignedActive();
                $receiptBookletsUnassigned = $this->receiptBooklets->listUnassigned();
                $receiptBookletsReturned = $this->receiptBooklets->listReturned();
                $drivers = $this->teams->listDrivers();
            } elseif ($tab === 'reports') {
                $cashSummary = $this->accounting->cashSummaryForRange($filters['date_from'], $filters['date_to']);
                $transferSummary = $this->accounting->transferSummaryForRange($filters['date_from'], $filters['date_to']);
            }
        } catch (Throwable $e) {
            Log::warning('Accounting page data unavailable.', ['message' => $e->getMessage()]);
        }

        return view('reports.accounting.index', [
            'filters' => $filters,
            'cashBundle' => $cashBundle,
            'transferRows' => $transferRows,
            'receiptBookletsAssigned' => $receiptBookletsAssigned,
            'receiptBookletsUnassigned' => $receiptBookletsUnassigned,
            'receiptBookletsReturned' => $receiptBookletsReturned,
            'drivers' => $drivers,
            'cashSummary' => $cashSummary,
            'transferSummary' => $transferSummary,
        ]);
    }

    public function storeCashSheet(AccountingCashSheetRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->accounting->upsertCashSheet(
                (string) $validated['sheet_date'],
                (float) $validated['opening_amount']
            );
        } catch (Throwable $e) {
            return $this->accountingRedirect($request, 'cash', (string) $validated['sheet_date'])
                ->with('error', $e->getMessage());
        }

        return $this->accountingRedirect($request, 'cash', (string) $validated['sheet_date'])
            ->with('status', 'Cash sheet saved.');
    }

    public function storeCashRow(AccountingCashRowStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $sheetDate = (string) $validated['sheet_date'];

        try {
            $sheetId = $this->accounting->ensureCashSheetForDate($sheetDate);
            $this->accounting->addCashSpendRow(
                $sheetId,
                (float) $validated['amount'],
                (string) $validated['paid_to'],
                (string) ($validated['note'] ?? '')
            );
        } catch (Throwable $e) {
            return $this->accountingRedirect($request, 'cash', $sheetDate)->with('error', $e->getMessage());
        }

        return $this->accountingRedirect($request, 'cash', $sheetDate)->with('status', 'Spend row added.');
    }

    public function updateCashRow(AccountingCashRowUpdateRequest $request, int $row): RedirectResponse
    {
        $validated = $request->validated();
        $redirectDate = (string) ($validated['date'] ?? $request->query('date', now()->toDateString()));

        try {
            $existing = $this->accounting->findCashSpendRow($row);
            if ($existing === null) {
                throw new \RuntimeException('Spend row not found.');
            }
            $this->accounting->updateCashSpendRow(
                $row,
                (float) $validated['amount'],
                (string) $validated['paid_to'],
                (string) ($validated['note'] ?? '')
            );
            $sheet = $this->accounting->getCashSheetForDate($redirectDate);
            if ($sheet === null || (int) $existing->sheet_id !== (int) $sheet->id) {
                $redirectDate = now()->toDateString();
            }
        } catch (Throwable $e) {
            return $this->accountingRedirect($request, 'cash', $redirectDate)->with('error', $e->getMessage());
        }

        return $this->accountingRedirect($request, 'cash', $redirectDate)->with('status', 'Spend row updated.');
    }

    public function destroyCashRow(Request $request, int $row): RedirectResponse
    {
        $redirectDate = (string) $request->query('date', now()->toDateString());

        try {
            $this->accounting->deleteCashSpendRow($row);
        } catch (Throwable $e) {
            return $this->accountingRedirect($request, 'cash', $redirectDate)->with('error', $e->getMessage());
        }

        return $this->accountingRedirect($request, 'cash', $redirectDate)->with('status', 'Spend row removed.');
    }

    public function storeTransferRow(AccountingTransferRowStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $transferDate = (string) $validated['transfer_date'];

        try {
            $this->accounting->addTransferRow(
                $transferDate,
                (float) $validated['amount'],
                (string) $validated['currency'],
                isset($validated['usd_rate']) ? (float) $validated['usd_rate'] : null,
                (string) $validated['person_name'],
                (string) ($validated['note'] ?? '')
            );
        } catch (Throwable $e) {
            return $this->accountingRedirect($request, 'transfers', $transferDate)->with('error', $e->getMessage());
        }

        return $this->accountingRedirect($request, 'transfers', $transferDate)->with('status', 'Transfer row added.');
    }

    public function updateTransferRow(AccountingTransferRowUpdateRequest $request, int $row): RedirectResponse
    {
        $validated = $request->validated();
        $redirectDate = (string) ($validated['date'] ?? $validated['transfer_date']);

        try {
            $this->accounting->updateTransferRow(
                $row,
                (string) $validated['transfer_date'],
                (float) $validated['amount'],
                (string) $validated['currency'],
                isset($validated['usd_rate']) ? (float) $validated['usd_rate'] : null,
                (string) $validated['person_name'],
                (string) ($validated['note'] ?? '')
            );
        } catch (Throwable $e) {
            return $this->accountingRedirect($request, 'transfers', $redirectDate)->with('error', $e->getMessage());
        }

        return $this->accountingRedirect($request, 'transfers', $redirectDate)->with('status', 'Transfer row updated.');
    }

    public function destroyTransferRow(Request $request, int $row): RedirectResponse
    {
        $redirectDate = (string) $request->query('date', now()->toDateString());

        try {
            $this->accounting->deleteTransferRow($row);
        } catch (Throwable $e) {
            return $this->accountingRedirect($request, 'transfers', $redirectDate)->with('error', $e->getMessage());
        }

        return $this->accountingRedirect($request, 'transfers', $redirectDate)->with('status', 'Transfer row removed.');
    }

    public function storeReceiptBooklets(DeliveriesReceiptBookletStoreRequest $request): RedirectResponse
    {
        try {
            $result = $this->receiptBooklets->addBookletsFromRange(
                (int) $request->validated('first_number'),
                (int) $request->validated('last_number')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->accountingRedirect($request, 'receipts')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklets_store_failed', ['message' => $e->getMessage()]);

            return $this->accountingRedirect($request, 'receipts')->with('error', 'Could not add receipt booklets.');
        }

        $message = 'Added '.$result['added'].' receipt booklet(s).';
        if (($result['skipped'] ?? 0) > 0) {
            $message .= ' Skipped '.$result['skipped'].' duplicate booklet(s).';
        }

        return $this->accountingRedirect($request, 'receipts')->with('status', $message);
    }

    public function assignReceiptBooklet(DeliveriesReceiptBookletAssignRequest $request): RedirectResponse
    {
        try {
            $this->receiptBooklets->assignByStartNumber(
                (int) $request->validated('start_number'),
                (string) $request->validated('driver_name')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->accountingRedirect($request, 'receipts')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklet_assign_failed', ['message' => $e->getMessage()]);

            return $this->accountingRedirect($request, 'receipts')->with('error', 'Could not assign receipt booklet.');
        }

        return $this->accountingRedirect($request, 'receipts')->with('status', 'Receipt booklet assigned.');
    }

    public function returnReceiptBooklet(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'booklet_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->receiptBooklets->markReturned((int) $validated['booklet_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->accountingRedirect($request, 'receipts')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklet_return_failed', ['message' => $e->getMessage()]);

            return $this->accountingRedirect($request, 'receipts')->with('error', 'Could not mark receipt booklet as returned.');
        }

        return $this->accountingRedirect($request, 'receipts')->with('status', 'Receipt booklet marked as returned.');
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
            return $this->accountingRedirect($request, 'receipts')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklet_update_failed', ['message' => $e->getMessage()]);

            return $this->accountingRedirect($request, 'receipts')->with('error', 'Could not update receipt booklet.');
        }

        return $this->accountingRedirect($request, 'receipts')->with('status', 'Receipt booklet updated.');
    }

    public function destroyReceiptBooklet(Request $request, int $booklet): RedirectResponse
    {
        try {
            $this->receiptBooklets->deleteBooklet($booklet);
        } catch (\InvalidArgumentException $e) {
            return $this->accountingRedirect($request, 'receipts')->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('accounting.receipt_booklet_delete_failed', ['message' => $e->getMessage()]);

            return $this->accountingRedirect($request, 'receipts')->with('error', 'Could not delete receipt booklet.');
        }

        return $this->accountingRedirect($request, 'receipts')->with('status', 'Receipt booklet deleted.');
    }

    public function exportCashPdf(AccountingSheetExportRequest $request): Response
    {
        $date = (string) $request->validated('date');
        $bundle = $this->accounting->cashSheetBundle($date);

        $pdf = Pdf::loadView('reports.accounting.cash-pdf', [
            'sheetDate' => $date,
            'bundle' => $bundle,
            ...ReportPdfBranding::viewData(),
        ]);

        return $pdf->download('accounting-cash-'.$date.'.pdf');
    }

    public function exportTransfersPdf(AccountingSheetExportRequest $request): Response
    {
        $date = (string) $request->validated('date');
        $rows = $this->accounting->listTransferRowsForDate($date);
        $iqdTotal = 0.0;
        foreach ($rows as $row) {
            $iqdTotal += $this->accounting->transferIqdEquivalent($row);
        }

        $pdf = Pdf::loadView('reports.accounting.transfers-pdf', [
            'sheetDate' => $date,
            'rows' => $rows,
            'iqdTotal' => $iqdTotal,
            ...ReportPdfBranding::viewData(),
        ]);

        return $pdf->download('accounting-transfers-'.$date.'.pdf');
    }

    public function exportSummaryPdf(AccountingSummaryExportRequest $request): Response
    {
        $validated = $request->validated();
        $dateFrom = (string) $validated['date_from'];
        $dateTo = (string) $validated['date_to'];

        $pdf = Pdf::loadView('reports.accounting.summary-pdf', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'cashSummary' => $this->accounting->cashSummaryForRange($dateFrom, $dateTo),
            'transferSummary' => $this->accounting->transferSummaryForRange($dateFrom, $dateTo),
            ...ReportPdfBranding::viewData(),
        ]);

        return $pdf->download('accounting-summary-'.$dateFrom.'-'.$dateTo.'.pdf');
    }

    public function exportSummaryCsv(AccountingSummaryExportRequest $request): BinaryFileResponse
    {
        $validated = $request->validated();
        $dateFrom = (string) $validated['date_from'];
        $dateTo = (string) $validated['date_to'];

        return Excel::download(
            new AccountingSummaryExport(
                $this->accounting->cashSummaryForRange($dateFrom, $dateTo),
                $this->accounting->transferSummaryForRange($dateFrom, $dateTo)
            ),
            'accounting-summary-'.$dateFrom.'-'.$dateTo.'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    private function accountingRedirect(Request $request, string $tab, ?string $date = null): RedirectResponse
    {
        $query = array_merge($request->query(), ['tab' => $tab]);
        if ($date !== null && in_array($tab, ['cash', 'transfers'], true)) {
            $query['date'] = $date;
        }

        return redirect()->route('reports.accounting.index', $query);
    }
}
