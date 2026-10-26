<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PromotionsAssignmentsTransferRequest extends FormRequest
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
            'target_promoter_id' => ['required', 'integer', 'min:1', 'different:promoter_id'],
            'tab' => ['nullable', 'string', 'in:setup,assignments,schedule'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_promoter_id.different' => 'Choose a different promoter.',
        ];
    }
}
