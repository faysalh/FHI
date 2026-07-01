<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PromotionsPromoterStoreRequest extends FormRequest
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
            'employee_name' => ['required', 'string', 'max:200'],
            'vehicle' => ['nullable', 'string', 'max:500'],
            'tab' => ['nullable', 'string', 'in:setup,assignments,schedule'],
        ];
    }
}
