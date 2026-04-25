<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SalesByItemAverageReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $today = Carbon::now()->toDateString();
        if (! $this->filled('date_from')) {
            $this->merge(['date_from' => $today]);
        }
        if (! $this->filled('date_to')) {
            $this->merge(['date_to' => $today]);
        }
        if (! $this->filled('per_page')) {
            $this->merge(['per_page' => 25]);
        }
        if (! $this->filled('page')) {
            $this->merge(['page' => 1]);
        }
        if (! $this->filled('working_days')) {
            $this->merge(['working_days' => 0]);
        }
        if ($this->has('cities')) {
            $raw = $this->input('cities');
            if (! is_array($raw)) {
                $raw = $raw !== null && $raw !== '' ? [(string) $raw] : [];
            }
            $this->merge(['cities' => array_values(array_filter(array_map('strval', $raw)))]);
        } else {
            $this->merge(['cities' => []]);
        }
        $this->merge([
            'q' => $this->input('q') !== null ? trim((string) $this->input('q')) : '',
            'category' => $this->input('category') !== null ? trim((string) $this->input('category')) : '',
            'exclude_category' => $this->input('exclude_category') !== null ? trim((string) $this->input('exclude_category')) : '',
        ]);
    }

    /**
     * @return array<string, array<int, string|object>>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:500'],
            'exclude_category' => ['nullable', 'string', 'max:500'],
            'cities' => ['sometimes', 'array', 'max:500'],
            'cities.*' => ['string', 'max:200'],
            'working_days' => ['nullable', 'integer', 'min:0', 'max:400'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('date_from');
            $to = $this->input('date_to');
            if (! is_string($from) || ! is_string($to)) {
                return;
            }
            $start = strtotime($from);
            $end = strtotime($to);
            if ($start === false || $end === false) {
                return;
            }
            $days = ($end - $start) / 86400;
            if ($days > 400) {
                $validator->errors()->add('date_to', 'Date range cannot exceed 400 days.');
            }
        });
    }
}
