<?php

namespace App\Support;

use App\Models\Event;
use App\Services\RegistrationForm;
use Illuminate\Validation\Rule;

/**
 * B2/D-Registration — one rule set shared by the final registration Form
 * Request and (Stage D) the per-step validation endpoint, so the two
 * never drift apart. Steps follow the spec's 7-step order; `event_id` is
 * deliberately not a step field — the event is always derived server-side
 * (RegistrationEvent::current()), never trusted from the client. Steps 4
 * and 5 are the event's own questions (RegistrationForm).
 */
class RegistrationRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function stepRules(int $step, ?Event $event = null, bool $final = false): array
    {
        return match ($step) {
            1 => [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255'],
                // Optional; the format is still checked when one is given.
                'mobile' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{7,20}$/'],
            ],
            2 => [
                'organisation' => ['required', 'string', 'max:255'],
                'job_title' => ['required', 'string', 'max:255'],
                // Optional; "Prefer not to say" submits nothing.
                'industry' => ['nullable', 'string', Rule::in(config('seminar.industries'))],
                'organisation_size' => ['nullable', 'string', Rule::in(config('seminar.organisation_sizes'))],
                // "How did you hear about us?" Optional. `partner` names one
                // of the event's partners; that it really is one is checked
                // against the server-side event (RegistrationController).
                // Naming a partner is attribution only; sharing details with
                // them is the separate, unticked-by-default box.
                'referral_source' => ['nullable', 'string', Rule::in([...array_keys(config('seminar.referral_sources', [])), 'partner'])],
                'referral_partner_id' => ['exclude_unless:referral_source,partner', 'required', 'integer'],
                'share_with_partner' => ['exclude_unless:referral_source,partner', 'boolean'],
            ],
            3 => [
                // An event can be in person only (Event::offersOnline()).
                'attendance_mode' => ['required', Rule::in($event && ! $event->offersOnline() ? ['in_person'] : ['online', 'in_person'])],
                // An invitation code only means anything for an in-person
                // seat; with Online it's dropped from the validated data.
                // Whether the code is any good is VoucherService::resolve()'s
                // job (RegistrationController), not a rule here.
                'voucher_code' => ['exclude_unless:attendance_mode,in_person', 'nullable', 'string', 'max:32'],
            ],
            // The event's own questions (RegistrationForm): step 4 holds the
            // standard ones; step 5 is optional and skipped while empty.
            4 => $event ? app(RegistrationForm::class)->rules($event, 1, $final) : [],
            5 => $event ? app(RegistrationForm::class)->rules($event, 2, $final) : [],
            6 => [
                'networking_consent' => ['boolean'],
            ],
            7 => [
                'lumko_marketing_consent' => ['boolean'],
            ],
            default => [],
        };
    }

    /** Messages for the built-in fields, shared by the step check and the final submit. */
    public static function messages(): array
    {
        return [
            'attendance_mode.in' => 'This event is in person only.',
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    /** The final submit: every step, with file answers checked too. */
    public static function allRules(?Event $event = null): array
    {
        $rules = [];

        for ($step = 1; $step <= 7; $step++) {
            $rules = array_merge($rules, self::stepRules($step, $event, final: true));
        }

        return $rules;
    }
}
