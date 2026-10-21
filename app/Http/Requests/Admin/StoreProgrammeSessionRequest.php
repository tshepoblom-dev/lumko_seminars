<?php

namespace App\Http\Requests\Admin;

use App\Models\ProgrammeSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgrammeSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ProgrammeSession::class)
            && $this->user()->can('update', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            ...self::sharedRules($this->route('event')->id),
        ];
    }

    /**
     * The event comes from the URL, never from the form; a session's
     * speakers must already be linked to that event.
     */
    public static function sharedRules(int $eventId): array
    {
        return [
            'speaker_ids' => ['nullable', 'array'],
            'speaker_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('event_speaker', 'speaker_id')->where('event_id', $eventId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'speaker_ids.*.exists' => 'Link this speaker to the event (Speakers) before adding them to a session.',
        ];
    }
}
