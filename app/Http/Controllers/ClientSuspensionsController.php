<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ClientSuspensionsExport;
use App\Http\Requests\ClientSuspensionStoreRequest;
use App\Repositories\DamagesCatalogRepository;
use App\Services\ClientSuspensionSqliteService;
use App\Support\ReportPdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ClientSuspensionsController extends Controller
{
    public function __construct(
        private readonly ClientSuspensionSqliteService $suspensions,
        private readonly DamagesCatalogRepository $clients,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $matches = [];
        $errorMessage = null;

        if ($search !== '') {
            try {
                $matches = $this->clients->searchClientsWithSalesman($search, 40);
            } catch (Throwable $e) {
                Log::warning('client_suspensions.search_failed', ['message' => $e->getMessage()]);
                $errorMessage = 'Could not search clients.';
            }
        }

        return view('reports.client-suspensions.index', [
            'search' => $search,
            'matches' => $matches,
            'suspended' => $this->suspensions->list($search),
            'suspendedIds' => $this->suspensions->suspendedIdSet(),
            'errorMessage' => $errorMessage,
        ]);
    }

    public function store(ClientSuspensionStoreRequest $request): RedirectResponse
    {
        $accountId = (string) $request->validated('client_account_id');
        $search = trim((string) ($request->validated('q') ?? ''));

        try {
            $client = $this->clients->findClientWithSalesman($accountId);
        } catch (Throwable $e) {
            Log::warning('client_suspensions.lookup_failed', ['message' => $e->getMessage()]);

            return $this->redirectToIndex($search)->with('error', 'Could not look up that client.');
        }

        if ($client === null) {
            return $this->redirectToIndex($search)->with('error', 'That client was not found.');
        }

        try {
            $this->suspensions->suspend(
                (string) ($client->account_id ?? ''),
                (string) ($client->client_code ?? ''),
                (string) ($client->client_name ?? ''),
                (string) ($client->salesman_name ?? ''),
                (string) (\App\Support\ReportAuthSession::username() ?? ''),
            );
        } catch (InvalidArgumentException $e) {
            return $this->redirectToIndex($search)->with('error', $e->getMessage());
        }

        return $this->redirectToIndex($search)->with('status', 'Client suspended.');
    }

    public function destroy(Request $request, string $client): RedirectResponse
    {
        $search = trim((string) $request->query('q', ''));
        $this->suspensions->release($client);

        return $this->redirectToIndex($search)->with('status', 'Suspension removed.');
    }

    public function exportPdf(Request $request): Response|RedirectResponse
    {
        $search = trim((string) $request->query('q', ''));

        try {
            $rows = $this->suspensions->list($search);
        } catch (Throwable $e) {
            Log::error('client_suspensions.pdf_export_failed', ['message' => $e->getMessage()]);

            return $this->redirectToIndex($search)->with('error', 'Could not build the suspensions PDF.');
        }

        $branding = InvoiceBrandingSettingsController::getSettings();
        $pdf = Pdf::loadView('reports.client-suspensions.pdf', [
            'rows' => $rows,
            'search' => $search,
            ...ReportPdfBranding::viewData($branding),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('client-suspensions-'.now()->format('Y-m-d').'.pdf');
    }

    public function exportCsv(Request $request): BinaryFileResponse|RedirectResponse
    {
        $search = trim((string) $request->query('q', ''));

        try {
            $rows = $this->suspensions->list($search);
        } catch (Throwable $e) {
            Log::error('client_suspensions.csv_export_failed', ['message' => $e->getMessage()]);

            return $this->redirectToIndex($search)->with('error', 'Could not build the suspensions CSV.');
        }

        return Excel::download(
            new ClientSuspensionsExport($rows),
            'client-suspensions-'.now()->format('Y-m-d').'.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    private function redirectToIndex(string $search): RedirectResponse
    {
        return redirect()->route('reports.client-suspensions.index', $search === '' ? [] : ['q' => $search]);
    }
}
