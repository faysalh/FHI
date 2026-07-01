<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountingTransferRowStoreRequest extends FormRequest
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
            'transfer_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', Rule::in(['IQD', 'USD'])],
            'usd_rate' => ['nullable', 'numeric', 'min:0', 'required_if:currency,USD'],
            'person_name' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'tab' => ['nullable', 'string', 'in:transfers'],
        ];
    }
}
