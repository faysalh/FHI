<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PdaVoucherRequest extends FormRequest
{
    public const DEFAULT_NOTE = 'Pocket WebPDA';

    public function authorize(): bool
    {
        return true;
    }

    public static function noteOrDefault(string $notes): string
    {
        $note = trim($notes);

        return $note === '' ? self::DEFAULT_NOTE : $note;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (! is_array($lines)) {
            $lines = [];
        }
        $clean = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $itemId = strtoupper(trim((string) ($line['item_id'] ?? '')));
            if ($itemId === '') {
                continue;
            }
            $clean[] = [
                'item_id' => $itemId,
                'unit_row_id' => strtoupper(trim((string) ($line['unit_row_id'] ?? ''))),
                'qty' => $line['qty'] ?? null,
                'price' => $line['price'] ?? null,
                'discount' => 0,
            ];
        }

        $this->merge([
            'kind' => (string) $this->input('kind', 'sale'),
            'paid' => $this->input('paid', 0),
            'discount' => 0,
            'notes' => trim((string) $this->input('notes', '')),
            'lines' => $clean,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:sale,order,return,receipt'],
            'paid' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['array'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.unit_row_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lat = (float) $this->input('lat', 0);
            $lng = (float) $this->input('lng', 0);
            if ($lat === 0.0 && $lng === 0.0) {
                $validator->errors()->add('lat', 'Location permission is required before this voucher can be saved.');
            }
            $kind = (string) $this->input('kind');
            $lines = $this->input('lines');
            if ($kind === 'receipt' && (float) $this->input('paid', 0) <= 0) {
                $validator->errors()->add('paid', 'Enter the amount collected.');
            }
            if ($kind !== 'receipt' && (! is_array($lines) || $lines === [])) {
                $validator->errors()->add('lines', 'Add at least one item.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lat.required' => 'Location permission is required before this voucher can be saved.',
            'lng.required' => 'Location permission is required before this voucher can be saved.',
        ];
    }
}
