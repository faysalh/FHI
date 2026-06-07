<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardMetricsRequest extends FormRequest
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
            'salesman_id' => ['nullable', 'string', 'max:36'],
            'saved_governorate_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
