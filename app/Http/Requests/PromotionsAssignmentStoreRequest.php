<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\PromotionsWeekdays;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PromotionsAssignmentStoreRequest extends FormRequest
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
            'promoter_id' => ['required', 'integer', 'min:1'],
            'client_account_id' => ['required', 'string', 'max:64'],
            'client_name' => ['required', 'string', 'max:500'],
            'daily_visits' => ['sometimes', 'boolean'],
            'visit_days' => ['nullable', 'array'],
            'visit_days.*' => ['integer', 'in:0,1,2,3,4,6'],
            'tab' => ['nullable', 'string', 'in:setup,assignments,schedule'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $dailyVisits = (bool) $this->boolean('daily_visits');
            $visitDays = PromotionsWeekdays::resolveVisitDaysFromInput(
                (array) $this->input('visit_days', []),
                $dailyVisits
            );

            try {
                PromotionsWeekdays::validateVisitDays($visitDays, $dailyVisits);
            } catch (\InvalidArgumentException $e) {
                $validator->errors()->add('visit_days', $e->getMessage());
            }
        });
    }

    /**
     * @return list<int>
     */
    public function resolvedVisitDays(): array
    {
        return PromotionsWeekdays::resolveVisitDaysFromInput(
            (array) $this->input('visit_days', []),
            (bool) $this->boolean('daily_visits')
        );
    }
}
