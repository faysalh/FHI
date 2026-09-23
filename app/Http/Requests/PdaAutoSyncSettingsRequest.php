<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PdaAutoSyncSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'interval_seconds' => $this->input('interval_seconds') !== null
                ? (int) $this->input('interval_seconds')
                : null,
            'agent_id' => $this->input('agent_id') !== null ? trim((string) $this->input('agent_id')) : 'all',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'interval_seconds' => ['required_if:enabled,true', 'nullable', 'integer', 'min:10', 'max:86400'],
            'agent_id' => ['nullable', 'string', 'max:36'],
        ];
    }
}
