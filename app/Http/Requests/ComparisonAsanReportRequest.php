<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\ComparisonMonthSpan;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class ComparisonAsanReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $today = Carbon::now();
        if (! $this->filled('date_from')) {
            $this->merge(['date_from' => $today->copy()->startOfMonth()->subMonth()->toDateString()]);
        }
        if (! $this->filled('date_to')) {
            $this->merge(['date_to' => $today->toDateString()]);
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
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'salesman_id' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:200'],
            'saved_governorate_id' => ['nullable', 'integer', 'min:0'],
            'metrics' => ['sometimes', 'array', 'min:1'],
            'metrics.*' => ['string', 'in:quantity,amount,weight'],
            'exclude_category' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                $months = ComparisonMonthSpan::between(
                    (string) $this->input('date_from'),
                    (string) $this->input('date_to')
                );
            } catch (InvalidArgumentException) {
                return;
            }
            if (count($months) > ComparisonMonthSpan::MAX_MONTHS) {
                $validator->errors()->add('date_to', 'Choose a range of '.ComparisonMonthSpan::MAX_MONTHS.' months or fewer.');
            }
        });
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
