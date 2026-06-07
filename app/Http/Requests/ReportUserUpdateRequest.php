<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $keys = $this->input('report_keys');
        if (! is_array($keys)) {
            $keys = $keys !== null && $keys !== '' ? [(string) $keys] : [];
        }
        $this->merge([
            'is_super_admin' => $this->boolean('is_super_admin'),
            'report_keys' => array_values(array_filter(array_map('strval', $keys))),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_super_admin' => ['boolean'],
            'report_keys' => ['array'],
            'report_keys.*' => ['string', 'max:80'],
            'password' => ['nullable', 'string', 'min:6', 'max:200'],
            'password_confirmation' => ['nullable', 'same:password'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('is_super_admin')) {
                return;
            }
            $keys = $this->input('report_keys');
            if (! is_array($keys) || $keys === []) {
                $validator->errors()->add('report_keys', 'Select at least one report for this user.');
            }
        });
    }
}
