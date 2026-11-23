<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PdaQuestionnaireStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $answers = $this->input('answers');
        $clean = [];
        if (is_array($answers)) {
            foreach ($answers as $questionId => $value) {
                $clean[strtoupper(trim((string) $questionId))] = trim((string) $value);
            }
        }

        $this->merge([
            'customer_id' => strtoupper(trim((string) $this->input('customer_id', ''))),
            'visit_id' => strtoupper(trim((string) $this->input('visit_id', ''))),
            'answers' => $clean,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'visit_id' => ['nullable', 'uuid'],
            'answers' => ['array'],
            'answers.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
