<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DamagesEntryStoreRequest extends FormRequest
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
            'occurred_date' => ['required', 'date'],
            'main_item_id' => ['required', 'string', 'max:100'],
            'client_account_id' => ['required', 'string', 'max:100'],
            'damaged_pieces' => ['required', 'integer', 'min:1', 'max:100000000'],
            'salesman_id' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
