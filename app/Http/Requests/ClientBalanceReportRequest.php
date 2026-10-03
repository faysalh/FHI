<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientBalanceReportRequest extends FormRequest
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
            'salesman_id' => ['nullable', 'string', 'max:50'],
            'year_id' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'integer', 'min:0', 'max:255'],
            'hide_zero' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100,250'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'hide_zero' => $this->boolean('hide_zero'),
        ]);
    }
}
