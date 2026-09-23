<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManufacturingIndexRequest extends FormRequest
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
            'tab' => ['nullable', 'string', 'in:stock,items,purchases,exports'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array{tab: string, date_from: string, date_to: string}
     */
    public function filters(): array
    {
        $tab = (string) $this->input('tab', 'stock');
        if (! in_array($tab, ['stock', 'items', 'purchases', 'exports'], true)) {
            $tab = 'stock';
        }

        $today = now()->toDateString();
        $dateFrom = $this->filled('date_from')
            ? (string) $this->input('date_from')
            : now()->startOfMonth()->toDateString();
        $dateTo = $this->filled('date_to')
            ? (string) $this->input('date_to')
            : $today;

        return [
            'tab' => $tab,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }
}
