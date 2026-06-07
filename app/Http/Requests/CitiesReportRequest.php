<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CitiesReportRequest extends FormRequest
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
        if (! $this->has('group_by_client')) {
            $this->merge(['group_by_client' => true]);
        }
        if ($this->has('group_by_client')) {
            $value = $this->input('group_by_client');
            if (is_string($value)) {
                $this->merge([
                    'group_by_client' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
                ]);
            }
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
        if ($this->has('governorate_members')) {
            $rawMembers = $this->input('governorate_members');
            if (! is_array($rawMembers)) {
                $rawMembers = $rawMembers !== null && $rawMembers !== '' ? [(string) $rawMembers] : [];
            }
            $this->merge(['governorate_members' => array_values(array_filter(array_map('strval', $rawMembers)))]);
        } else {
            $this->merge(['governorate_members' => []]);
        }

        if ($this->has('chart_show')) {
            $rawShow = $this->input('chart_show');
            if (! is_array($rawShow)) {
                $rawShow = $rawShow !== null && $rawShow !== '' ? [(string) $rawShow] : [];
            }
            $this->merge(['chart_show' => array_values(array_filter(array_map('strval', $rawShow)))]);
        }

        $this->merge([
            'breakdown' => $this->boolean('breakdown'),
            'breakdown_by_client' => $this->boolean('breakdown_by_client'),
            'q' => $this->input('q') !== null ? (string) $this->input('q') : '',
            'panel' => in_array($this->input('panel'), ['table', 'charts'], true) ? $this->input('panel') : 'table',
            'city_page' => in_array($this->input('city_page'), ['overview', 'governorate-breakdown', 'pie-charts', 'salesman-pie'], true)
                ? (string) $this->input('city_page')
                : 'overview',
            'governorate_city' => $this->input('governorate_city') !== null ? trim((string) $this->input('governorate_city')) : '',
            'pie_category' => $this->input('pie_category') !== null ? trim((string) $this->input('pie_category')) : '',
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
            'group_by_client' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100,250'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'breakdown' => ['boolean'],
            'breakdown_by_client' => ['boolean'],
            'q' => ['nullable', 'string', 'max:200'],
            'cities' => ['sometimes', 'array', 'max:500'],
            'cities.*' => ['string', 'max:200'],
            'salesman_ids' => ['sometimes', 'array', 'max:500'],
            'salesman_ids.*' => ['string', 'max:100'],
            'governorate_members' => ['sometimes', 'array', 'max:500'],
            'governorate_members.*' => ['string', 'max:200'],
            'panel' => ['sometimes', 'in:table,charts'],
            'chart_show' => ['sometimes', 'array', 'max:10'],
            'chart_show.*' => ['string', 'in:amount,units_sold,weight_total,customer_count,invoice_count'],
            'city_page' => ['sometimes', 'in:overview,governorate-breakdown,pie-charts,salesman-pie'],
            'governorate_city' => ['nullable', 'string', 'max:200'],
            'pie_category' => ['nullable', 'string', 'max:500'],
            'exclude_category' => ['nullable', 'string', 'max:500'],
            'saved_governorate_id' => ['nullable', 'integer', 'min:1'],
            'edit_governorate_id' => ['nullable', 'integer', 'min:1'],
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
