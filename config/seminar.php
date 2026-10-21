<?php

/*
|--------------------------------------------------------------------------
| Seminar platform settings
|--------------------------------------------------------------------------
|
| Values that vary per deployment live in .env, never in code. Bank details
| in particular must not be committed to the repository.
|
*/

return [

    // Legal entity shown in payment instructions and email footers.
    'organiser_legal_name' => env('SEMINAR_ORGANISER_LEGAL_NAME', 'Lumko Business Technology Partners'),

    // Link to the organiser's POPIA privacy notice. The notice itself is a
    // legal document the organiser must supply — nothing is fabricated here.
    // When unset, no privacy link is rendered anywhere (see launch checklist).
    'privacy_notice_url' => env('SEMINAR_PRIVACY_NOTICE_URL'),

    'registration' => [
        // Registration submits allowed per minute from one IP address. The
        // per-email cap (3/hour) is the real abuse control and is not
        // configurable; this one is deliberately generous because many
        // registrants arrive from shared IPs (a municipal office or venue
        // Wi-Fi is one public address). The master spec said 5; lower it
        // here if you see abuse.
        'ip_per_minute' => (int) env('SEMINAR_REGISTRATION_IP_PER_MINUTE', 30),
    ],

    'payment' => [
        'account_name' => env('SEMINAR_BANK_ACCOUNT_NAME'),
        'bank' => env('SEMINAR_BANK_NAME'),
        'account_number' => env('SEMINAR_BANK_ACCOUNT_NUMBER'),
        'branch_code' => env('SEMINAR_BANK_BRANCH_CODE'),
        'account_type' => env('SEMINAR_BANK_ACCOUNT_TYPE'),
        'reference_prefix' => env('SEMINAR_PAYMENT_REFERENCE_PREFIX', 'EVENT-'),
    ],

    // B2/D-Options — canonical option lists. Controllers and Vue pages read
    // these via Inertia shared props rather than hand-rolled per-file
    // constants, and validation rules use Rule::in() against them.
    'interests' => [
        'AI', 'Data analytics', 'Digital transformation', 'Automation',
        'Improving organisational processes', 'Municipal/government technology',
        'Data governance', 'Technology strategy', 'Other',
    ],

    'improvement_areas' => [
        'Manual processes', 'Reporting', 'Data management', 'Software systems',
        'Websites/digital presence', 'Mobile applications', 'IT infrastructure',
        'Cybersecurity', 'Automation', 'Other',
    ],

    // Optional dropdowns on registration step 2 (Organisation & role);
    // also the allowed values RegistrationRules validates against.
    'industries' => [
        'Government/Municipal', 'Financial services', 'Healthcare', 'Education',
        'Retail', 'Manufacturing', 'Telecommunications', 'Agriculture',
        'Non-profit', 'Professional services', 'Other',
    ],

    'organisation_sizes' => [
        '1-10', '11-50', '51-200', '201-500', '501-1000', '1000+',
    ],

    // How long after an event ends it still counts as "current" for portal
    // access (B1) and how long the feedback form stays open (B10).
    'post_event_days' => env('SEMINAR_POST_EVENT_DAYS', 90),
    'feedback_days' => env('SEMINAR_FEEDBACK_DAYS', 30),

    // B15 — starts in Content-Security-Policy-Report-Only; flip to true
    // (enforcing) only after the Stage H rehearsal shows zero violations.
    'csp_enforce' => env('SEMINAR_CSP_ENFORCE', false),

    // B3 — resource external links must be https unless explicitly opted
    // out (a rare http-only third-party source).
    'allow_http_resource_links' => env('SEMINAR_ALLOW_HTTP_RESOURCE_LINKS', false),

    // Admin communications (Admin → Communications). Emails are queued one
    // per person and spread out at this rate; set it at or below the SMTP
    // host's own sending limit (many shared hosts cap mail per hour).
    'communications' => [
        'per_minute' => (int) env('SEMINAR_MAIL_PER_MINUTE', 60),
    ],

    // Speaker report links (Event → Speakers → Send report link) stop
    // working this many days after they're sent.
    'speaker_report_days' => (int) env('SEMINAR_SPEAKER_REPORT_DAYS', 30),

    // Cookieless site analytics (Admin → Analytics). Page hits are kept
    // this many days; "live now" presence rows are pruned hourly.
    'analytics' => [
        'enabled' => (bool) env('SEMINAR_ANALYTICS_ENABLED', true),
        'retention_days' => (int) env('SEMINAR_ANALYTICS_RETENTION_DAYS', 365),
    ],

    // Proof-of-payment files are deleted this many days after the event
    // ends; the payment record (amount/status/history) is kept (B3).
    'pop_retention_days' => env('SEMINAR_POP_RETENTION_DAYS', 180),

    // The "Lumko Seminars" series explained on the home page. Presented as
    // an expert advisory and learning programme, never as a sales pitch:
    // keep claims out of this copy. Edit here when the owner revises it.
    'series' => [
        'name' => 'Lumko Seminars',
        'pillars' => [
            [
                'name' => 'Technology',
                'summary' => 'The tools and systems changing how work is done, explained by the people who build and run them.',
            ],
            [
                'name' => 'Business',
                'summary' => 'What those changes mean for strategy, operations and the decisions leaders have to make.',
            ],
            [
                'name' => 'Society',
                'summary' => 'The effect on people, public institutions and the communities they serve.',
            ],
        ],
        // Examples only: the series is not limited to these fields, and the
        // page words them that way ("fields such as …").
        'example_sectors' => ['mining', 'education', 'governance', 'financial services', 'legal'],
    ],

    // Exact wording shown to the attendee for each consent checkbox (B7).
    // The UI and ConsentService both read from here so the ledger always
    // stores the words the attendee actually saw. Bump the version string
    // whenever the wording changes materially.
    'consent_wording' => [
        'networking' => [
            'text' => 'Show my name, organisation, job title and stated interests to other opted-in attendees of this event, so we can connect.',
            'version' => 'v1',
        ],
        'lumko_marketing' => [
            'text' => 'Lumko Business Technology Partners may email me about future events, products and services. I can withdraw this at any time from my account.',
            'version' => 'v1',
        ],
        'follow_up_request' => [
            'text' => 'I would like Lumko to follow up with me about the topics I mentioned in my feedback.',
            'version' => 'v1',
        ],
        // A template: ConsentService::wording() fills in :speaker, so the
        // ledger stores the exact sentence the attendee saw.
        'speaker_contact' => [
            'text' => 'Share my name, email, organisation and job title with :speaker so they can contact me about their session. I can withdraw this any time from my account.',
            'version' => 'v1',
        ],
        // A template like speaker_contact (:partner is filled in). Needs the
        // owner's legal review before launch (docs/launch-checklist.md).
        'partner_contact' => [
            'text' => 'Share my name, email, organisation and job title with :partner, a partner of this event, so they can contact me about their products and services. :partner is responsible for how it uses my details. I can withdraw this any time from my account; that stops further access, but not copies they have already made.',
            'version' => 'v1',
        ],
    ],

    // "How did you hear about us?" on the registration form. Keys are
    // stored on event_registrations.referral_source; the event's published
    // partners are offered alongside these (stored as `partner`).
    'referral_sources' => [
        'linkedin' => 'LinkedIn',
        'lumko_email' => 'An email from Lumko',
        'colleague' => 'A colleague or friend',
        'search' => 'Search engine',
        'lumko_website' => 'The Lumko website',
        'other' => 'Somewhere else',
    ],

];
