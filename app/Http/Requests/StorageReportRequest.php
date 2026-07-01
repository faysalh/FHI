<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\StorageReportAccess;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorageReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $today = Carbon::now()->toDateString();
        if (! $this->filled('as_of_date')) {
            $this->merge(['as_of_date' => $today]);
        }
        if (! $this->filled('per_page')) {
            $this->merge(['per_page' => 250]);
        }
        if (! $this->filled('page')) {
            $this->merge(['page' => 1]);
        }

        $this->merge([
            'storages' => $this->normalizeArrayInput('storages'),
            'categories' => $this->normalizeArrayInput('categories'),
            'exclude_categories' => $this->normalizeArrayInput('exclude_categories'),
            'items' => $this->normalizeArrayInput('items'),
            'exclude_items' => $this->normalizeArrayInput('exclude_items'),
            'cities' => $this->normalizeArrayInput('cities'),
            'saved_governorate_id' => (int) ($this->input('saved_governorate_id') ?? 0),
            'show_category' => $this->boolean('show_category'),
            'show_item_code' => $this->boolean('show_item_code'),
        ]);

        $applied = StorageReportAccess::applySessionToValidated($this->all());
        $this->merge($applied);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $categories = array_values(array_filter(
                array_map(static fn ($val): string => trim((string) $val), (array) ($data['categories'] ?? [])),
                static fn (string $s): bool => $s !== ''
            ));
            $excludeCategories = array_values(array_filter(
                array_map(static fn ($val): string => trim((string) $val), (array) ($data['exclude_categories'] ?? [])),
                static fn (string $s): bool => $s !== ''
            ));
            $overlap = array_intersect($categories, $excludeCategories);
            if ($overlap !== []) {
                $v->errors()->add('exclude_categories', 'A category cannot be both included and excluded.');
            }

            $items = array_values(array_filter(
                array_map(static fn ($val): string => trim((string) $val), (array) ($data['items'] ?? [])),
                static fn (string $s): bool => $s !== ''
            ));
            $excludeItems = array_values(array_filter(
                array_map(static fn ($val): string => trim((string) $val), (array) ($data['exclude_items'] ?? [])),
                static fn (string $s): bool => $s !== ''
            ));
            $itemOverlap = array_intersect($items, $excludeItems);
            if ($itemOverlap !== []) {
                $v->errors()->add('exclude_items', 'An item cannot be both included and excluded.');
            }
        });
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'as_of_date' => ['required', 'date'],
            'storages' => ['sometimes', 'array', 'max:80'],
            'storages.*' => ['string', 'max:500'],
            'categories' => ['sometimes', 'array', 'max:80'],
            'categories.*' => ['string', 'max:500'],
            'exclude_categories' => ['sometimes', 'array', 'max:80'],
            'exclude_categories.*' => ['string', 'max:500'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*' => ['string', 'max:100'],
            'exclude_items' => ['sometimes', 'array', 'max:500'],
            'exclude_items.*' => ['string', 'max:100'],
            'cities' => ['sometimes', 'array', 'max:200'],
            'cities.*' => ['string', 'max:200'],
            'saved_governorate_id' => ['nullable', 'integer', 'min:0'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100,250'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'show_category' => ['sometimes', 'boolean'],
            'show_item_code' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeArrayInput(string $key): array
    {
        $raw = $this->input($key);
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $raw),
            static fn (string $s): bool => $s !== ''
        )));
    }
}
