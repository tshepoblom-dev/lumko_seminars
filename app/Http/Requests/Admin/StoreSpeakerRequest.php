<?php

namespace App\Http\Requests\Admin;

use App\Models\Speaker;
use Illuminate\Foundation\Http\FormRequest;

class StoreSpeakerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Speaker::class);
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
            // Email them a speaker portal invitation once created.
            'invite_to_portal' => ['nullable', 'boolean'],
            'bio' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'photo_alt' => ['nullable', 'required_with:photo', 'string', 'max:255'],
            'for_event_id' => ['nullable', 'integer', 'exists:events,id'],
        ];
    }
}
