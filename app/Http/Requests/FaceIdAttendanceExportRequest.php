<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FaceIdAttendanceExportRequest extends FormRequest
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array{date_from: string, date_to: string}
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $dateFrom = isset($validated['date_from']) ? (string) $validated['date_from'] : now()->startOfMonth()->toDateString();
        $dateTo = isset($validated['date_to']) ? (string) $validated['date_to'] : now()->toDateString();

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }
}
