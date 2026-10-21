<?php

/*
|--------------------------------------------------------------------------
| Privacy Policy and Terms & Conditions
|--------------------------------------------------------------------------
|
| The documents themselves are resources/js/Pages/Public/Legal/*.vue; the
| facts only the organiser can supply live here, set through .env, so they
| can be completed without a code change. Anything still unset shows as
| "[TO COMPLETE]" and the pages carry a "draft" notice until it's filled.
|
| Bumping a version makes everyone accept again on their next visit
| (EnsureLegalAccepted). Change a version only when the wording changes in
| a way people should re-read, and update `effective_date` with it.
|
*/

$missing = '[TO COMPLETE]';

return [

    'versions' => [
        'terms' => env('LEGAL_TERMS_VERSION') ?: '2026-09-28',
        // 2026-10-07: speaker and partner contact sharing, referrals and client records.
        'privacy' => env('LEGAL_PRIVACY_VERSION') ?: '2026-10-07',
    ],

    'effective_date' => env('LEGAL_EFFECTIVE_DATE') ?: '2026-10-07',

    // Set to true once the documents have been reviewed and every field below
    // is filled in; until then the pages say they're a draft.
    'reviewed' => (bool) env('LEGAL_REVIEWED', false),

    'placeholder' => $missing,

    // `?:` rather than env()'s default, so an empty `KEY=` line in .env
    // still counts as missing.
    'organiser' => [
        'legal_name' => env('SEMINAR_ORGANISER_LEGAL_NAME') ?: 'Lumko Business Technology Partners',
        'registration_number' => env('LEGAL_COMPANY_REGISTRATION_NUMBER') ?: $missing,
        'physical_address' => env('LEGAL_PHYSICAL_ADDRESS') ?: $missing,
        'contact_email' => env('LEGAL_CONTACT_EMAIL') ?: env('MAIL_FROM_ADDRESS') ?: $missing,
        'information_officer_name' => env('LEGAL_INFORMATION_OFFICER_NAME') ?: $missing,
        'information_officer_email' => env('LEGAL_INFORMATION_OFFICER_EMAIL') ?: $missing,
        'hosting_provider' => env('LEGAL_HOSTING_PROVIDER') ?: $missing,
    ],

    // In-person tickets are paid by bank transfer; what happens when someone
    // cancels (or the event is cancelled) is a business decision.
    'refund_policy' => env('LEGAL_REFUND_POLICY') ?: $missing,

];
