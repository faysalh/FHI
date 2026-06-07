<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class ComparisonReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $today = Carbon::now()->toDateString();
        if (! $this->filled('date_from_1')) {
            $this->merge(['date_from_1' => $today]);
        }
        if (! $this->filled('date_to_1')) {
            $this->merge(['date_to_1' => $today]);
        }
        if (! $this->filled('date_from_2')) {
            $this->merge(['date_from_2' => $today]);
        }
        if (! $this->filled('date_to_2')) {
            $this->merge(['date_to_2' => $today]);
        }

        $this->merge([
            'salesman_id' => $this->input('salesman_id') !== null ? trim((string) $this->input('salesman_id')) : '',
            'city' => $this->input('city') !== null ? trim((string) $this->input('city')) : '',
            'saved_governorate_id' => (int) ($this->input('saved_governorate_id') ?? 0),
            'metrics' => $this->normalizeMetrics($this->input('metrics')),
            'exclude_category' => $this->input('exclude_category') !== null ? trim((string) $this->input('exclude_category')) : '',
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'date_from_1' => ['required', 'date'],
            'date_to_1' => ['required', 'date', 'after_or_equal:date_from_1'],
            'date_from_2' => ['required', 'date'],
            'date_to_2' => ['required', 'date', 'after_or_equal:date_from_2'],
            'salesman_id' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:200'],
            'saved_governorate_id' => ['nullable', 'integer', 'min:0'],
            'metrics' => ['sometimes', 'array', 'min:1'],
            'metrics.*' => ['string', 'in:quantity,amount,weight'],
            'exclude_category' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeMetrics(mixed $input): array
    {
        $allowed = ['quantity', 'amount', 'weight'];
        $raw = is_array($input) ? $input : ($input !== null && $input !== '' ? [(string) $input] : []);
        $out = [];
        foreach ($raw as $metric) {
            $value = trim((string) $metric);
            if (in_array($value, $allowed, true)) {
                $out[$value] = $value;
            }
        }
        if ($out === []) {
            return $allowed;
        }

        return array_values($out);
    }
}
