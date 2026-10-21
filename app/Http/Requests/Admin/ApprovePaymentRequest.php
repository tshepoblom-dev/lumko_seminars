<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApprovePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('payment'));
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
            // B4 — approving without a proof on file (e.g. confirmed by
            // bank statement) requires this flag and PaymentService then
            // requires `note` too.
            'manual_override' => ['boolean'],
        ];
    }
}
