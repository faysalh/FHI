<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $keys = $this->input('report_keys');
        if (! is_array($keys)) {
            $keys = $keys !== null && $keys !== '' ? [(string) $keys] : [];
        }
        $this->merge([
            'is_super_admin' => $this->boolean('is_super_admin'),
            'report_keys' => array_values(array_filter(array_map('strval', $keys))),
            'deliveries_can_filter_date' => $this->boolean('deliveries_can_filter_date'),
            'deliveries_can_filter_city' => $this->boolean('deliveries_can_filter_city'),
            'deliveries_can_filter_storage' => $this->boolean('deliveries_can_filter_storage'),
            'deliveries_can_filter_salesman' => $this->boolean('deliveries_can_filter_salesman'),
            'deliveries_can_filter_status' => $this->boolean('deliveries_can_filter_status'),
            'deliveries_can_edit_status' => $this->boolean('deliveries_can_edit_status'),
            'deliveries_default_storage' => trim((string) $this->input('deliveries_default_storage', '')),
            'storage_can_filter_storage' => $this->boolean('storage_can_filter_storage'),
            'storage_allowed_storages' => $this->normalizeStorageAllowedInput(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function normalizeStorageAllowedInput(): array
    {
        $raw = $this->input('storage_allowed_storages');
        if (! is_array($raw)) {
            return $raw !== null && $raw !== '' ? [trim((string) $raw)] : [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $raw
        ), static fn (string $s): bool => $s !== '')));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_super_admin' => ['boolean'],
            'report_keys' => ['array'],
            'report_keys.*' => ['string', 'max:80'],
            'deliveries_can_filter_date' => ['sometimes', 'boolean'],
            'deliveries_can_filter_city' => ['sometimes', 'boolean'],
            'deliveries_can_filter_storage' => ['sometimes', 'boolean'],
            'deliveries_can_filter_salesman' => ['sometimes', 'boolean'],
            'deliveries_can_filter_status' => ['sometimes', 'boolean'],
            'deliveries_can_edit_status' => ['sometimes', 'boolean'],
            'deliveries_default_storage' => ['nullable', 'string', 'max:500'],
            'storage_can_filter_storage' => ['sometimes', 'boolean'],
            'storage_allowed_storages' => ['sometimes', 'array', 'max:80'],
            'storage_allowed_storages.*' => ['string', 'max:500'],
            'password' => ['nullable', 'string', 'min:6', 'max:200'],
            'password_confirmation' => ['nullable', 'same:password'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('is_super_admin')) {
                return;
            }
            $keys = $this->input('report_keys');
            if (! is_array($keys) || $keys === []) {
                $validator->errors()->add('report_keys', 'Select at least one report for this user.');
            }
            if (is_array($keys) && in_array('deliveries', $keys, true) && ! $this->boolean('deliveries_can_filter_storage')) {
                if (trim((string) $this->input('deliveries_default_storage', '')) === '') {
                    $validator->errors()->add(
                        'deliveries_default_storage',
                        'Choose a default storage when the user cannot change the storage filter.'
                    );
                }
            }
            if (is_array($keys) && in_array('storage', $keys, true) && ! $this->boolean('storage_can_filter_storage')) {
                $allowed = $this->input('storage_allowed_storages');
                if (! is_array($allowed) || $allowed === []) {
                    $validator->errors()->add(
                        'storage_allowed_storages',
                        'Select at least one assigned storage when the user cannot change the storage filter.'
                    );
                }
            }
        });
    }
}
