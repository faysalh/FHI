<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GovernorateStoreRequest extends FormRequest
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
            'governorate_id' => ['nullable', 'integer', 'min:1'],
            'governorate_name' => ['required', 'string', 'max:200'],
            'governorate_city' => ['required', 'string', 'max:200'],
            'governorate_members' => ['sometimes', 'array', 'max:500'],
            'governorate_members.*' => ['string', 'max:200'],
        ];
    }
}
