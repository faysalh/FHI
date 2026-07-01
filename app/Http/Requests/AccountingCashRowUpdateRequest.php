<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountingCashRowUpdateRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0'],
            'paid_to' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'tab' => ['nullable', 'string', 'in:cash'],
            'date' => ['nullable', 'date'],
        ];
    }
}
