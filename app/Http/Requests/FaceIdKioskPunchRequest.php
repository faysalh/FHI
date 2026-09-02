<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\FaceIdSqliteService;
use Illuminate\Foundation\Http\FormRequest;

class FaceIdKioskPunchRequest extends FormRequest
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
            'descriptor' => ['required', 'array', 'size:'.FaceIdSqliteService::DESCRIPTOR_LENGTH],
            'descriptor.*' => ['required', 'numeric'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return list<float>
     */
    public function descriptor(): array
    {
        return array_map('floatval', $this->validated('descriptor'));
    }

    /**
     * @return array{latitude: float, longitude: float, accuracy: ?float}
     */
    public function location(): array
    {
        $validated = $this->validated();

        return [
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
            'accuracy' => isset($validated['location_accuracy'])
                ? (float) $validated['location_accuracy']
                : null,
        ];
    }
}
