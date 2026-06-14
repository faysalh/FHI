<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeliveriesReportRequest extends FormRequest
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
        if ($this->has('cities')) {
            $raw = $this->input('cities');
            if (! is_array($raw)) {
                $raw = $raw !== null && $raw !== '' ? [(string) $raw] : [];
            }
            $this->merge(['cities' => array_values(array_filter(array_map('strval', $raw)))]);
        } else {
            $this->merge(['cities' => []]);
        }
        $includeAmount = $this->input('include_amount', false);
        $includeWeight = $this->input('include_weight', false);
        $this->merge([
            'storage' => $this->input('storage') !== null ? trim((string) $this->input('storage')) : '',
            'delivery_status' => $this->input('delivery_status') !== null ? trim((string) $this->input('delivery_status')) : '',
            'tab' => $this->input('tab') !== null ? trim((string) $this->input('tab')) : 'report',
            'team_date' => $this->input('team_date') !== null ? trim((string) $this->input('team_date')) : $today,
            'include_amount' => in_array($includeAmount, [true, 1, '1', 'true', 'on'], true),
            'include_weight' => in_array($includeWeight, [true, 1, '1', 'true', 'on'], true),
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
            'cities' => ['sometimes', 'array', 'max:500'],
            'cities.*' => ['string', 'max:200'],
            'storage' => ['nullable', 'string', 'max:500'],
            'delivery_status' => ['nullable', 'string', 'in:delivered,not_delivered'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'tab' => ['nullable', 'string', 'in:report,setup,daily-teams,batch-assignment,receipts'],
            'team_date' => ['nullable', 'date'],
            'include_amount' => ['sometimes', 'boolean'],
            'include_weight' => ['sometimes', 'boolean'],
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
