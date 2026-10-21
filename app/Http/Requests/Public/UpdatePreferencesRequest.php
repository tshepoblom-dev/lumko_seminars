<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'organisation' => ['required', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:255'],
            'networking_consent' => ['boolean'],
            'lumko_marketing_consent' => ['boolean'],
        ];
    }
}
