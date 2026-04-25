<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CustomerReportRequest;
use App\Services\CustomerReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Throwable;

class CustomerReportController extends Controller
{
    public function __construct(
        private readonly CustomerReportService $service
    ) {
    }

    public function index(CustomerReportRequest $request): View
    {
        $filters = $request->validated();

        try {
            $report = $this->service->buildReport($filters, $request);

            return view('reports.customers.index', [
                'rows' => $report['paginator'],
                'filters' => $filters,
                'table' => $report['table'],
                'errorMessage' => null,
            ]);
        } catch (Throwable $exception) {
            return view('reports.customers.index', [
                'rows' => null,
                'filters' => $filters,
                'table' => null,
                'errorMessage' => 'Unable to load customer reports right now. Please retry.',
            ]);
        }
    }

    public function data(CustomerReportRequest $request): JsonResponse
    {
        $filters = $request->validated();

        try {
            $report = $this->service->buildReport($filters, $request);
            $paginator = $report['paginator'];

            return response()->json([
                'ok' => true,
                'table' => $report['table'],
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to load customer reports right now. Please retry.',
            ], 500);
        }
    }
}
