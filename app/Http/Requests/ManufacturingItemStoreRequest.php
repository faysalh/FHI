<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManufacturingItemStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:500'],
            'code' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:100'],
            'tab' => ['nullable', 'string', 'in:items'],
        ];
    }
}
