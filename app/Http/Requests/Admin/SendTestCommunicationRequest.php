<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesCommunicationMessage;
use Illuminate\Foundation\Http\FormRequest;

class SendTestCommunicationRequest extends FormRequest
{
    use ValidatesCommunicationMessage;

    public function rules(): array
    {
        return [
            ...$this->messageRules(),
            'test_email' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [...$this->messageAttributes(), 'test_email' => 'test address'];
    }
}
