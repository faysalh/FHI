<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Repositories\RankingsReportRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RankingsReportRequest extends FormRequest
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
        if (! $this->filled('tab')) {
            $this->merge(['tab' => RankingsReportRepository::TAB_CLIENTS]);
        }
        if (! $this->filled('metric')) {
            $this->merge(['metric' => 'amount']);
        }
        if (! $this->filled('limit')) {
            $this->merge(['limit' => 10]);
        }

        $tab = strtolower(trim((string) $this->input('tab', RankingsReportRepository::TAB_CLIENTS)));
        if (! in_array($tab, RankingsReportRepository::TABS, true)) {
            $tab = RankingsReportRepository::TAB_CLIENTS;
        }
        $this->merge(['tab' => $tab]);

        $metric = strtolower(trim((string) $this->input('metric', 'amount')));
        if (! in_array($metric, RankingsReportRepository::METRICS, true)) {
            $metric = 'amount';
        }
        $this->merge(['metric' => $metric]);

        $limit = (int) $this->input('limit', 10);
        if (! in_array($limit, RankingsReportRepository::LIMITS, true)) {
            $limit = 10;
        }
        $this->merge(['limit' => $limit]);

        if ($this->has('salesman_ids')) {
            $raw = $this->input('salesman_ids');
            if (! is_array($raw)) {
                $raw = $raw !== null && $raw !== '' ? [(string) $raw] : [];
            }
            $this->merge(['salesman_ids' => array_values(array_filter(array_map('strval', $raw)))]);
        } else {
            $this->merge(['salesman_ids' => []]);
        }

        $this->merge([
            'storage' => $this->input('storage') !== null ? trim((string) $this->input('storage')) : '',
            'saved_governorate_id' => (int) ($this->input('saved_governorate_id') ?? 0),
        ]);
    }

    /**
     * @return array<string, array<int, string|object>>
     */
    public function rules(): array
    {
        return [
            'tab' => ['required', 'string', 'in:'.implode(',', RankingsReportRepository::TABS)],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'metric' => ['required', 'string', 'in:'.implode(',', RankingsReportRepository::METRICS)],
            'limit' => ['required', 'integer', 'in:'.implode(',', RankingsReportRepository::LIMITS)],
            'salesman_ids' => ['sometimes', 'array', 'max:100'],
            'salesman_ids.*' => ['string', 'max:64'],
            'storage' => ['nullable', 'string', 'max:500'],
            'saved_governorate_id' => ['nullable', 'integer', 'min:0'],
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
