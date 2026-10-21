<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CancelPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('payment'));
    }

    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:2000']];
    }
}
