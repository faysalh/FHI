<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SqliteAutoBackupSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'directory' => $this->input('directory') !== null ? trim((string) $this->input('directory')) : '',
            'time' => $this->input('time') !== null ? trim((string) $this->input('time')) : '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'directory' => ['required_if:enabled,true', 'nullable', 'string', 'max:500'],
            'time' => ['required_if:enabled,true', 'nullable', 'date_format:H:i'],
        ];
    }
}
