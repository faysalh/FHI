<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountingIndexRequest extends FormRequest
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
            'tab' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array{tab: string}
     */
    public function filters(): array
    {
        return [
            'tab' => 'receipts',
        ];
    }
}
