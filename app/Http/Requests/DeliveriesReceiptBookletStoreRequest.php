<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveriesReceiptBookletStoreRequest extends FormRequest
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
            'first_number' => ['required', 'integer', 'min:1'],
            'last_number' => ['required', 'integer', 'min:1', 'gte:first_number'],
            'tab' => ['nullable', 'string', 'in:receipts'],
        ];
    }
}
