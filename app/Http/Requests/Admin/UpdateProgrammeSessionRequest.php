<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgrammeSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('programmeSession'));
    }

    public function rules(): array
    {
        return StoreProgrammeSessionRequest::sharedRules($this->route('event')->id);
    }

    public function messages(): array
    {
        return (new StoreProgrammeSessionRequest)->messages();
    }
}
