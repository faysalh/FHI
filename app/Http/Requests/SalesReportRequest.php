<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $today = Carbon::now()->toDateString();
        if (! $this->filled('date_from')) {
            $this->merge(['date_from' => $today]);
        }
        if (! $this->filled('date_to')) {
            $this->merge(['date_to' => $today]);
        }
        if (! $this->has('group_by_client')) {
            $this->merge(['group_by_client' => true]);
        }
        if ($this->has('group_by_client')) {
            $value = $this->input('group_by_client');
            if (is_string($value)) {
                $this->merge([
                    'group_by_client' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
                ]);
            }
        }
        if (! $this->filled('per_page')) {
            $this->merge(['per_page' => 250]);
        }
        if (! $this->filled('page')) {
            $this->merge(['page' => 1]);
        }
        if ($this->has('customer_account_ids')) {
            $raw = $this->input('customer_account_ids');
            if (! is_array($raw)) {
                $raw = $raw !== null && $raw !== '' ? [(string) $raw] : [];
            }
            $this->merge(['customer_account_ids' => array_values(array_filter(array_map('strval', $raw)))]);
        } else {
            $this->merge(['customer_account_ids' => []]);
        }

        if ($this->has('salesman_ids')) {
            $rawSalesmen = $this->input('salesman_ids');
            if (! is_array($rawSalesmen)) {
                $rawSalesmen = $rawSalesmen !== null && $rawSalesmen !== '' ? [(string) $rawSalesmen] : [];
            }
            $this->merge(['salesman_ids' => array_values(array_filter(array_map('strval', $rawSalesmen)))]);
        } else {
            $this->merge(['salesman_ids' => []]);
        }

        $this->merge([
            'breakdown' => $this->boolean('breakdown'),
            'breakdown_by_client' => $this->boolean('breakdown_by_client'),
            'breakdown_items' => $this->boolean('breakdown_items'),
            'q' => $this->input('q') !== null ? (string) $this->input('q') : '',
            'saved_governorate_id' => (int) ($this->input('saved_governorate_id') ?? 0),
            'storage' => trim((string) ($this->input('storage') ?? '')),
            'include_quantity' => $this->resolveMetricToggle('include_quantity'),
            'include_amount' => $this->resolveMetricToggle('include_amount'),
            'include_weight' => $this->resolveMetricToggle('include_weight'),
        ]);
    }

    private function resolveMetricToggle(string $key): bool
    {
        $hasAnyMetricToggle = $this->has('include_quantity')
            || $this->has('include_amount')
            || $this->has('include_weight');

        if (! $hasAnyMetricToggle) {
            return true;
        }

        return $this->boolean($key);
    }

    /**
     * @return array<string, array<int, string|object>>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'group_by_client' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100,250'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'breakdown' => ['boolean'],
            'breakdown_by_client' => ['boolean'],
            'breakdown_items' => ['boolean'],
            'q' => ['nullable', 'string', 'max:200'],
            'customer_account_ids' => ['sometimes', 'array', 'max:500'],
            'customer_account_ids.*' => ['uuid'],
            'saved_governorate_id' => ['nullable', 'integer', 'min:0'],
            'salesman_ids' => ['sometimes', 'array', 'max:50'],
            'salesman_ids.*' => ['string', 'max:100'],
            'storage' => ['nullable', 'string', 'max:500'],
            'include_quantity' => ['boolean'],
            'include_amount' => ['boolean'],
            'include_weight' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('include_quantity')
                && ! $this->boolean('include_amount')
                && ! $this->boolean('include_weight')) {
                $validator->errors()->add('include_quantity', 'Show at least one metric column (quantity, amount, or weight).');
            }
        });

        $validator->after(function (Validator $validator): void {
            $from = $this->input('date_from');
            $to = $this->input('date_to');
            if (! is_string($from) || ! is_string($to)) {
                return;
            }
            $start = strtotime($from);
            $end = strtotime($to);
            if ($start === false || $end === false) {
                return;
            }
            $days = ($end - $start) / 86400;
            if ($days > 400) {
                $validator->errors()->add('date_to', 'Date range cannot exceed 400 days.');
            }
        });
    }
}
