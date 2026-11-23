<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManufacturingPurchaseStoreRequest extends FormRequest
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
            'item_id' => ['required', 'integer', 'min:1'],
            'purchase_date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'cost_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', Rule::in(['IQD', 'USD'])],
            'supplier_name' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'tab' => ['nullable', 'string', 'in:purchases'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ];
    }
}
