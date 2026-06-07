<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SqliteBackupCreateRequest extends FormRequest
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
        $keys = array_keys((array) config('reporting.sqlite_databases', []));

        return [
            'database_key' => ['nullable', 'string', Rule::in(array_merge(['all'], $keys))],
        ];
    }
}
