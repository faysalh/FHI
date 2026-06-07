<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\WorkingDays;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorageItemsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $today = Carbon::now()->toDateString();
        $this->merge(['as_of_date' => $today]);

        if (! $this->filled('per_page')) {
            $this->merge(['per_page' => 250]);
        }
        if (! $this->filled('page')) {
            $this->merge(['page' => 1]);
        }

        $excludeList = array_map(
            static fn ($v): string => trim((string) $v),
            (array) $this->input('exclude_categories', [])
        );
        $legacyExclude = $this->input('exclude_category');
        if ($legacyExclude !== null && trim((string) $legacyExclude) !== '') {
            $excludeList[] = trim((string) $legacyExclude);
        }

        $this->merge([
            'storage' => $this->input('storage') !== null ? trim((string) $this->input('storage')) : '',
            'category' => $this->input('category') !== null ? trim((string) $this->input('category')) : '',
            'item' => $this->input('item') !== null ? trim((string) $this->input('item')) : '',
            'exclude_categories' => array_values(array_unique(array_filter(
                $excludeList,
                static fn (string $s): bool => $s !== ''
            ))),
        ]);

        if (! $this->filled('sales_date_from')) {
            $this->merge(['sales_date_from' => $today]);
        }
        if (! $this->filled('sales_date_to')) {
            $this->merge(['sales_date_to' => $today]);
        }

        try {
            $from = (string) $this->input('sales_date_from', $today);
            $to = (string) $this->input('sales_date_to', $today);
            $this->merge([
                'working_days' => WorkingDays::countSalesPeriodWorkingDays($from, $to),
            ]);
        } catch (\Throwable) {
            $this->merge(['working_days' => 1]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $cat = trim((string) ($data['category'] ?? ''));
            $exList = array_values(array_filter(
                array_map(static fn ($val): string => trim((string) $val), (array) ($data['exclude_categories'] ?? [])),
                static fn (string $s): bool => $s !== ''
            ));
            if ($cat !== '' && in_array($cat, $exList, true)) {
                $v->errors()->add('exclude_categories', 'Remove the selected category from exclusions, or clear the category filter.');
            }
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            try {
                $from = Carbon::parse((string) ($data['sales_date_from'] ?? ''))->startOfDay();
                $to = Carbon::parse((string) ($data['sales_date_to'] ?? ''))->startOfDay();
                if ($from->greaterThan($to)) {
                    $v->errors()->add('sales_date_to', 'Sales period end must be on or after sales period start.');
                }
            } catch (\Throwable) {
                // Date rules already caught invalid values.
            }
        });
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'as_of_date' => ['sometimes', 'date'],
            'sales_date_from' => ['required', 'date'],
            'sales_date_to' => ['required', 'date'],
            'working_days' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'storage' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:500'],
            'exclude_categories' => ['sometimes', 'array', 'max:80'],
            'exclude_categories.*' => ['string', 'max:500'],
            'item' => ['nullable', 'string', 'max:300'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100,250'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
