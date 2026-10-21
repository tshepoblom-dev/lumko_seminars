<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('announcement'));
    }

    /** The event comes from the workspace URL, never from the form. */
    protected function prepareForValidation(): void
    {
        $this->merge(['event_id' => $this->route('event')?->id]);
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'exists:events,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'in:event_update,reminder,programme_update,resource_available,live_event_notice,post_event'],
            'audience' => ['nullable', 'in:all,online,in_person'],
            'published_at' => ['nullable', 'date'],
        ];
    }
}
