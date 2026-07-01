<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab' => ['nullable', 'string', 'in:cash,transfers,receipts,reports'],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array{tab: string, date: string, date_from: string, date_to: string}
     */
    public function filters(): array
    {
        $today = now()->toDateString();
        $tab = (string) ($this->input('tab') ?? 'cash');
        if (! in_array($tab, ['cash', 'transfers', 'receipts', 'reports'], true)) {
            $tab = 'cash';
        }

        $date = (string) ($this->input('date') ?? $today);
        $dateFrom = (string) ($this->input('date_from') ?? now()->startOfMonth()->toDateString());
        $dateTo = (string) ($this->input('date_to') ?? $today);

        return [
            'tab' => $tab,
            'date' => $date,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }
}
