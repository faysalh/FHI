<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DamagesPackagingStoreRequest extends FormRequest
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
            'main_item_id' => ['required', 'string', 'max:100'],
            'item_name' => ['required', 'string', 'max:500'],
            'pieces_per_main_unit' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
