<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'live' => $this->boolean('live'),
            'as_of_date' => $this->filled('as_of_date')
                ? trim((string) $this->input('as_of_date'))
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'salesman_id' => ['nullable', 'string', 'max:36'],
            'saved_governorate_id' => ['nullable', 'integer', 'min:1'],
            'as_of_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'live' => ['sometimes', 'boolean'],
        ];
    }
}
