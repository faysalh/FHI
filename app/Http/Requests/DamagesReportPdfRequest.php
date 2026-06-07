<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class DamagesReportPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('date_from')) {
            $this->merge(['date_from' => Carbon::now()->subDays(30)->toDateString()]);
        }
        if (! $this->has('date_to')) {
            $this->merge(['date_to' => Carbon::now()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'client_q' => ['nullable', 'string', 'max:200'],
            'item_q' => ['nullable', 'string', 'max:200'],
            'salesman_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, client_q: string, item_q: string, salesman_id: string}
     */
    public function filters(): array
    {
        $v = $this->validated();

        return [
            'date_from' => (string) $v['date_from'],
            'date_to' => (string) $v['date_to'],
            'client_q' => trim((string) ($v['client_q'] ?? '')),
            'item_q' => trim((string) ($v['item_q'] ?? '')),
            'salesman_id' => trim((string) ($v['salesman_id'] ?? '')),
        ];
    }
}
