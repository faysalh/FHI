<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManufacturingItemBulkImportRequest extends FormRequest
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
            'csv_file' => ['nullable', 'file', 'mimes:csv,txt', 'max:5120', 'required_without:bulk_lines'],
            'bulk_lines' => ['nullable', 'string', 'max:500000', 'required_without:csv_file'],
            'update_existing' => ['nullable', 'boolean'],
            'tab' => ['nullable', 'string', 'in:items'],
        ];
    }

    public function wantsUpdateExisting(): bool
    {
        return $this->boolean('update_existing');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'csv_file.required_without' => 'Choose a CSV file or paste item lines below.',
            'csv_file.mimes' => 'Upload a .csv file.',
            'csv_file.max' => 'CSV file must be 5 MB or smaller.',
            'bulk_lines.required_without' => 'Paste item lines or choose a CSV file.',
            'bulk_lines.max' => 'Pasted text is too long (500 KB max).',
        ];
    }
}
