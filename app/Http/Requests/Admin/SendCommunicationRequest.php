<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesCommunicationMessage;
use Illuminate\Foundation\Http\FormRequest;

class SendCommunicationRequest extends FormRequest
{
    use ValidatesCommunicationMessage;

    public function rules(): array
    {
        return [
            ...$this->messageRules(),
            'recipient_mode' => ['required', 'in:all,filtered,selected'],
            'filters' => ['nullable', 'array'],
            'user_ids' => ['required_if:recipient_mode,selected', 'array', 'max:5000'],
            'user_ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return ['user_ids.required_if' => 'Choose at least one person.'];
    }
}
