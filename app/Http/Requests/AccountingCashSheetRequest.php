<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountingCashSheetRequest extends FormRequest
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
            'sheet_date' => ['required', 'date'],
            'opening_amount' => ['required', 'numeric', 'min:0'],
            'tab' => ['nullable', 'string', 'in:cash'],
        ];
    }
}
