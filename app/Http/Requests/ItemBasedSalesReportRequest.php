<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class ItemBasedSalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $today = Carbon::now('Asia/Baghdad')->toDateString();
        $this->merge([
            'date_from' => $this->input('date_from', $today),
            'date_to' => $this->input('date_to', $today),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'salesman_id' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:200'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
