<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpeakerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('speaker'));
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'organisation' => ['nullable', 'string', 'max:255'],
            // Where their report link is emailed; never shown to attendees.
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'bio' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'photo_alt' => ['nullable', 'required_with:photo', 'string', 'max:255'],
            'photo_approved' => ['nullable', 'boolean'],
            'remove_photo' => ['nullable', 'boolean'],
        ];
    }
}
