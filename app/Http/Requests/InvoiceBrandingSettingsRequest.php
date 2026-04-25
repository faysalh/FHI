<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class InvoiceBrandingSettingsRequest extends FormRequest
{
    private const LOGO_RECOMMENDED_WIDTH = 800;
    private const LOGO_RECOMMENDED_HEIGHT = 800;

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
            'company_name' => ['required', 'string', 'max:255'],
            'company_mobile' => ['nullable', 'string', 'max:100'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'footer_note' => ['nullable', 'string', 'max:1000'],
            'invoice_direction' => ['nullable', 'in:rtl,ltr'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var UploadedFile|null $logo */
            $logo = $this->file('logo');
            if (! $logo instanceof UploadedFile) {
                return;
            }

            $size = @getimagesize($logo->getPathname());
            if (! is_array($size) || count($size) < 2) {
                return;
            }

            $width = (int) $size[0];
            $height = (int) $size[1];

            if ($width < 300 || $height < 300) {
                $validator->errors()->add(
                    'logo',
                    'Logo is too small. Use at least 300x300 px. Recommended: 800x800 px (1:1), PNG/WebP with transparent background.'
                );
            }

            if ($width > 2000 || $height > 2000) {
                $validator->errors()->add(
                    'logo',
                    'Logo is too large. Keep it at most 2000x2000 px and below 2 MB. Recommended: 800x800 px (1:1).'
                );
            }

            if ($height > 0) {
                $ratio = $width / $height;
                if ($ratio < 0.9 || $ratio > 1.1) {
                    $validator->errors()->add(
                        'logo',
                        'Logo ratio is not suitable. Use a square logo (1:1). Recommended size: 800x800 px.'
                    );
                }
            }
        });
    }
}

