<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PdaVisitEndRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'failed' => $this->boolean('failed'),
            'note' => trim((string) $this->input('note', '')),
            'reason_id' => $this->input('reason_id') === '' || $this->input('reason_id') === null
                ? null
                : $this->input('reason_id'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'failed' => ['boolean'],
            'reason_id' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('failed') && (int) $this->input('reason_id', 0) < 1) {
                $validator->errors()->add('reason_id', 'Choose a reason for a visit with no invoice.');
            }
        });
    }
}
