<?php

namespace App\Http\Requests\Public;

use App\Models\Event;
use App\Services\RegistrationForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * B10 — no `event_id` field: the event is always derived server-side
     * from the caller's registration (FeedbackController), never trusted
     * from the client.
     */
    public function rules(): array
    {
        // The event's own interest list (EnsureEventAttendee resolved the event).
        $event = $this->attributes->get('currentEvent');
        $topics = $event instanceof Event ? app(RegistrationForm::class)->topicOptions($event) : config('seminar.interests');

        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'biggest_takeaway' => ['nullable', 'string', 'max:2000'],
            'topics_next' => ['nullable', 'array', 'max:12'],
            'topics_next.*' => ['string', Rule::in($topics)],
            'wants_contact' => ['boolean'],
            'contact_topics' => ['nullable', 'array', 'max:12', 'required_if:wants_contact,true'],
            'contact_topics.*' => ['string', Rule::in($topics)],
            // Speakers to share contact details with (checked against the
            // event's own speakers in FeedbackController).
            'speaker_ids' => ['nullable', 'array', 'max:50'],
            'speaker_ids.*' => ['integer'],
        ];
    }
}
