<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SchemaBrowserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'table' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'in:10,20,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
