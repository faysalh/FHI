<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\FaceIdSqliteService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class FaceIdFaceDescriptorRequest extends FormRequest
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
        $length = FaceIdSqliteService::DESCRIPTOR_LENGTH;

        return [
            'descriptor' => ['sometimes', 'array', 'size:'.$length],
            'descriptor.*' => ['required_with:descriptor', 'numeric'],
            'descriptors' => ['sometimes', 'array', 'min:2', 'max:5'],
            'descriptors.*' => ['required_with:descriptors', 'array', 'size:'.$length],
            'descriptors.*.*' => ['required_with:descriptors', 'numeric'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasSingle = $this->has('descriptor') && is_array($this->input('descriptor'));
            $hasMulti = $this->has('descriptors') && is_array($this->input('descriptors'));

            if (! $hasSingle && ! $hasMulti) {
                $validator->errors()->add('descriptor', 'Either descriptor or descriptors is required.');

                return;
            }

            if ($hasSingle && $hasMulti) {
                $validator->errors()->add('descriptor', 'Send descriptor or descriptors, not both.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'Face descriptor validation failed.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * @return list<float>
     */
    public function descriptor(): array
    {
        if ($this->has('descriptors') && is_array($this->input('descriptors'))) {
            /** @var list<list<float|int>> $sets */
            $sets = $this->validated('descriptors');

            return FaceIdSqliteService::averageDescriptors($sets);
        }

        return array_map('floatval', $this->validated('descriptor'));
    }
}
