<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PromotionsScheduleExportRequest extends FormRequest
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
            'promoter_id' => ['required', 'integer', 'min:1'],
            'week_start' => ['required', 'date'],
        ];
    }
}
