<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveriesReceiptBookletUpdateRequest extends FormRequest
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
            'start_number' => ['nullable', 'integer', 'min:1'],
            'end_number' => ['nullable', 'integer', 'min:1'],
            'driver_name' => ['nullable', 'string', 'max:200'],
            'unassign' => ['nullable', 'boolean'],
            'undo_return' => ['nullable', 'boolean'],
            'tab' => ['nullable', 'string', 'in:receipts'],
        ];
    }
}
