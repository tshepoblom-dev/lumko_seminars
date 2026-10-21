<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proof' => [
                'required', 'file', 'max:5120',
                'mimes:jpg,jpeg,png,pdf',
                'mimetypes:image/jpeg,image/png,application/pdf',
            ],
        ];
    }

    /**
     * B3 — `dimensions:` can't be a static rule alongside a PDF-permitting
     * `mimes:` list: Laravel's dimensions rule calls getimagesize() and
     * fails outright for a non-image file (it would reject every PDF).
     * So image dimensions are checked here, only when the upload is
     * actually an image.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $file = $this->file('proof');

            if (! $file || ! str_starts_with((string) $file->getMimeType(), 'image/')) {
                return;
            }

            $size = @getimagesize($file->getRealPath());

            if ($size === false || $size[0] > 6000 || $size[1] > 6000) {
                $validator->errors()->add('proof', 'The image must not be larger than 6000x6000 pixels.');
            }
        });
    }
}
