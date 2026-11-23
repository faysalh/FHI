<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PromotionsAssignmentsClearRequest extends FormRequest
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
            'promoter_id' => ['required', 'integer', 'min:1'],
            'tab' => ['nullable', 'string', 'in:setup,assignments,schedule'],
        ];
    }
}
