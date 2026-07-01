<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SalesByItemReportRequest extends FormRequest
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
            $this->merge(['per_page' => 250]);
        }
        if (! $this->filled('page')) {
            $this->merge(['page' => 1]);
        }

        foreach (['cities', 'categories'] as $arrayKey) {
            if ($this->has($arrayKey)) {
                $raw = $this->input($arrayKey);
                if (! is_array($raw)) {
                    $raw = $raw !== null && $raw !== '' ? [(string) $raw] : [];
                }
                $this->merge([$arrayKey => array_values(array_filter(array_map('strval', $raw)))]);
            } else {
                $this->merge([$arrayKey => []]);
            }
        }

        if ($this->has('price_tiers')) {
            $rawTiers = $this->input('price_tiers');
            if (! is_array($rawTiers)) {
                $rawTiers = $rawTiers !== null && $rawTiers !== '' ? [(string) $rawTiers] : [];
            }
            $tiers = [];
            foreach ($rawTiers as $tier) {
                if (is_numeric($tier)) {
                    $tiers[] = (int) $tier;
                }
            }
            $this->merge(['price_tiers' => array_values(array_unique($tiers))]);
        } else {
            $this->merge(['price_tiers' => []]);
        }

        if ($this->has('metrics')) {
            $rawMetrics = $this->input('metrics');
            if (! is_array($rawMetrics)) {
                $rawMetrics = $rawMetrics !== null && $rawMetrics !== '' ? [(string) $rawMetrics] : [];
            }
            $metrics = [];
            foreach ($rawMetrics as $metric) {
                if (is_string($metric)) {
                    $metric = strtolower(trim($metric));
                    if (in_array($metric, ['qty', 'amt', 'wt'], true)) {
                        $metrics[] = $metric;
                    }
                }
            }
            $this->merge(['metrics' => array_values(array_unique($metrics))]);
        } else {
            $this->merge(['metrics' => []]);
        }

        $this->merge([
            'salesman_id' => $this->input('salesman_id') !== null ? trim((string) $this->input('salesman_id')) : '',
            'storage' => $this->input('storage') !== null ? trim((string) $this->input('storage')) : '',
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
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100,250'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'salesman_id' => ['nullable', 'string', 'max:36'],
            'storage' => ['nullable', 'string', 'max:500'],
            'cities' => ['sometimes', 'array', 'max:500'],
            'cities.*' => ['string', 'max:200'],
            'categories' => ['sometimes', 'array', 'max:500'],
            'categories.*' => ['string', 'max:500'],
            'price_tiers' => ['sometimes', 'array', 'max:5'],
            'price_tiers.*' => ['integer', 'between:1,5'],
            'metrics' => ['sometimes', 'array', 'max:3'],
            'metrics.*' => ['string', 'in:qty,amt,wt'],
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
