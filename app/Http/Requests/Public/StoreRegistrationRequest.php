<?php

namespace App\Http\Requests\Public;

use App\Services\RegistrationForm;
use App\Support\RegistrationEvent;
use App\Support\RegistrationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * B2 — `event_id` is deliberately not accepted from the client. The
     * event is always derived server-side (the current published,
     * registration-open event); RegistrationController ignores any
     * event_id the client sends.
     */
    public function rules(): array
    {
        // Accepting the Terms & Privacy Policy is asked on the review step,
        // so it's part of the final submit, not the per-step validation.
        return [...RegistrationRules::allRules(RegistrationEvent::current()), 'accept_legal' => ['accepted']];
    }

    public function messages(): array
    {
        $event = RegistrationEvent::current();

        return [
            ...($event ? app(RegistrationForm::class)->messages($event) : []),
            ...RegistrationRules::messages(),
            'accept_legal.accepted' => 'Please accept the Terms & Conditions and the Privacy Policy to register.',
        ];
    }

    /** Errors name the event's question, not "answers.12". */
    public function attributes(): array
    {
        $event = RegistrationEvent::current();

        return $event ? app(RegistrationForm::class)->attributes($event) : [];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
        ]);
    }
}
