<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GeneralLedgerReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'year_id' => ['nullable', 'string', 'max:50'],
            'account_id' => ['nullable', 'string', 'max:50'],
            'salesman_id' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'integer', 'min:0', 'max:255'],
            'city' => ['nullable', 'string', 'max:200'],
            'account_type' => ['nullable', 'integer', 'in:0,3,5'],
            'show_as_summary' => ['nullable', 'boolean'],
            'transaction_type_id' => ['nullable', 'string', 'max:50'],
            'agent_id' => ['nullable', 'string', 'max:50'],
            'cross_account_id' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100,250'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'show_as_summary' => $this->boolean('show_as_summary'),
            'city' => trim((string) $this->input('city', '')),
        ]);
    }
}
