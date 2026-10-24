<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\StorageReportAccess;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorageQuantityReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('balance_mode')) {
            $this->merge(['balance_mode' => 'normal']);
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
            'hide_negative_balances' => $this->boolean('hide_negative_balances'),
            'hide_zero_balances' => $this->boolean('hide_zero_balances'),
            'as_of_datetime' => $this->normalizeAdvDatetimeInput($this->input('as_of_datetime')),
        ]);

        $applied = StorageReportAccess::applySessionToValidated($this->all());
        $this->merge($applied);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $categories = $this->cleanList($data['categories'] ?? []);
            $excludeCategories = $this->cleanList($data['exclude_categories'] ?? []);
            if (array_intersect($categories, $excludeCategories) !== []) {
                $v->errors()->add('exclude_categories', 'A category cannot be both included and excluded.');
            }

            $items = $this->cleanList($data['items'] ?? []);
            $excludeItems = $this->cleanList($data['exclude_items'] ?? []);
            if (array_intersect($items, $excludeItems) !== []) {
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
            'balance_mode' => ['required', 'in:normal,adv'],
            'year_id' => ['nullable', 'string', 'max:100'],
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
            'store_title_id' => ['nullable', 'string', 'max:100'],
            'expiration_date' => ['nullable', 'date'],
            'as_of_datetime' => ['nullable', 'string', 'max:32'],
            'serial' => ['nullable', 'string', 'max:250'],
            'batch_no' => ['nullable', 'string', 'max:250'],
            'hide_negative_balances' => ['sometimes', 'boolean'],
            'hide_zero_balances' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100,250'],
            'page' => ['sometimes', 'integer', 'min:1'],
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

    /**
     * @return list<string>
     */
    private function cleanList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($val): string => trim((string) $val), $raw),
            static fn (string $s): bool => $s !== ''
        ));
    }

    private function normalizeAdvDatetimeInput(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim(str_replace('T', ' ', (string) $raw));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
