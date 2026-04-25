<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CustomerReportRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CustomerReportService
{
    public function __construct(
        private readonly CustomerReportRepository $repository
    ) {
    }

    /**
     * @param  array{q?: string|null, city?: string|null, per_page?: int|null}  $filters
     * @return array{
     *   table: string,
     *   column_map: array<string, string>,
     *   paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator
     * }
     */
    public function buildReport(array $filters, Request $request): array
    {
        try {
            return $this->repository->getCustomerReport($filters);
        } catch (Throwable $exception) {
            Log::error('Sales and customer report generation failed.', [
                'request_id' => (string) $request->header('X-Request-Id', ''),
                'report' => 'sales_customer_report',
                'db_host' => (string) config('database.connections.'.config('database.default').'.host', ''),
                'db_connection' => (string) config('database.default'),
                'filters' => $filters,
                'error_code' => $exception->getCode(),
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
