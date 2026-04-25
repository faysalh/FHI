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
            $this->merge(['per_page' => 25]);
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

        $this->merge([
            'breakdown' => $this->boolean('breakdown'),
            'breakdown_by_client' => $this->boolean('breakdown_by_client'),
            'q' => $this->input('q') !== null ? (string) $this->input('q') : '',
        ]);
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
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'breakdown' => ['boolean'],
            'breakdown_by_client' => ['boolean'],
            'q' => ['nullable', 'string', 'max:200'],
            'customer_account_ids' => ['sometimes', 'array', 'max:500'],
            'customer_account_ids.*' => ['uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
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
