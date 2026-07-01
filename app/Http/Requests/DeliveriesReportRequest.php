<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DeliveriesReportAccess;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class DeliveriesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (trim((string) $this->input('tab', '')) === 'receipts') {
            throw new HttpResponseException(
                redirect()->route('reports.accounting.index', ['tab' => 'receipts'])
            );
        }

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
        if ($this->has('salesman_ids')) {
            $rawSalesmen = $this->input('salesman_ids');
            if (! is_array($rawSalesmen)) {
                $rawSalesmen = $rawSalesmen !== null && $rawSalesmen !== '' ? [(string) $rawSalesmen] : [];
            }
            $this->merge(['salesman_ids' => array_values(array_filter(array_map('strval', $rawSalesmen)))]);
        } else {
            $this->merge(['salesman_ids' => []]);
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
            'invoice_search' => $this->input('invoice_search') !== null
                ? trim((string) $this->input('invoice_search'))
                : '',
        ]);

        $applied = DeliveriesReportAccess::applySessionToValidated($this->all());
        $this->merge($applied);
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
            'salesman_ids' => ['sometimes', 'array', 'max:100'],
            'salesman_ids.*' => ['string', 'max:64'],
            'storage' => ['nullable', 'string', 'max:500'],
            'delivery_status' => ['nullable', 'string', 'in:delivered,not_delivered'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'tab' => ['nullable', 'string', 'in:report,setup,daily-teams,batch-assignment'],
            'team_date' => ['nullable', 'date'],
            'include_amount' => ['sometimes', 'boolean'],
            'include_weight' => ['sometimes', 'boolean'],
            'invoice_search' => ['nullable', 'string', 'max:100'],
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
