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
        ];
    }

    /**
     * @return list<float>
     */
    public function descriptor(): array
    {
        return array_map('floatval', $this->validated('descriptor'));
    }
}
