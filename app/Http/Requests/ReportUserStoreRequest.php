<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportUserStoreRequest extends FormRequest
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
            'username' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:6', 'max:200'],
            'password_confirmation' => ['required', 'same:password'],
            'is_super_admin' => ['boolean'],
            'report_keys' => ['array'],
            'report_keys.*' => ['string', 'max:80'],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'Username may only contain letters, numbers, dots, underscores, and hyphens.',
        ];
    }
}
