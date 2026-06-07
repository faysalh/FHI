<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DamagesIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('date_from')) {
            $this->merge(['date_from' => Carbon::now()->startOfMonth()->toDateString()]);
        }
        if (! $this->filled('date_to')) {
            $this->merge(['date_to' => Carbon::now()->endOfMonth()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab' => ['sometimes', 'string', Rule::in(['damages', 'packaging'])],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'client_q' => ['nullable', 'string', 'max:200'],
            'item_q' => ['nullable', 'string', 'max:200'],
            'salesman_id' => ['nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100, 250])],
        ];
    }

    /**
     * @return array{tab: string, date_from: string, date_to: string, client_q: string, item_q: string, salesman_id: string, per_page: int}
     */
    public function filters(): array
    {
        $v = $this->validated();

        return [
            'tab' => (string) ($v['tab'] ?? 'damages'),
            'date_from' => (string) $v['date_from'],
            'date_to' => (string) $v['date_to'],
            'client_q' => trim((string) ($v['client_q'] ?? '')),
            'item_q' => trim((string) ($v['item_q'] ?? '')),
            'salesman_id' => trim((string) ($v['salesman_id'] ?? '')),
            'per_page' => (int) ($v['per_page'] ?? 25),
        ];
    }
}
