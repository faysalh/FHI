<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveriesReceiptBookletAssignRequest extends FormRequest
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
            'start_number' => ['required', 'integer', 'min:1'],
            'driver_name' => ['required', 'string', 'max:200'],
            'tab' => ['nullable', 'string', 'in:receipts'],
        ];
    }
}
