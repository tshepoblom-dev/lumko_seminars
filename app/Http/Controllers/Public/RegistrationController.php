<?php

namespace App\Http\Controllers\Public;

use App\Actions\SendAccountSetupLink;
use App\Actions\StartInPersonPlace;
use App\Enums\ConsentType;
use App\Enums\PartnerFeature;
use App\Enums\UserRole;
use App\Exceptions\VoucherNotApplicable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreRegistrationRequest;
use App\Models\Event;
use App\Models\EventPartner;
use App\Models\EventRegistration;
use App\Models\Partner;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\ExistingAccountRegistrationAttempt;
use App\Notifications\RegistrationAddedToAccount;
use App\Notifications\RegistrationConfirmed;
use App\Notifications\TicketConfirmed;
use App\Services\ConsentService;
use App\Services\RegistrationForm;
use App\Services\VoucherService;
use App\Support\Audit;
use App\Support\Legal;
use App\Support\RegistrationEvent;
use App\Support\RegistrationRules;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * B2 — registration never auto-logs the visitor in. A brand-new or
 * not-yet-verified account gets a "confirm your email and set a
 * password" link (ConfirmEmailAndSetPassword). An anonymous submission
 * for an existing *verified* account is saved too, but held at arm's
 * length until its owner signs in, and the owner is emailed with a link
 * to cancel it (storeForExistingAccount). The HTTP response to the
 * submitter is uniform either way, so neither branch is observable from
 * the response alone (no enumeration).
 */
class RegistrationController extends Controller
{
    public function __construct(
        private ConsentService $consentService,
        private VoucherService $vouchers,
        private RegistrationForm $form,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $event = $this->currentRegistrationEvent();

        if (! $event) {
            // D-Public home — a friendly page, not a bare 404: distinguish
            // "this event already happened" from "nothing published yet"
            // so the copy can point at the right next step.
            $mostRecent = Event::publiclyVisible()->orderByDesc('starts_at')->first();

            return Inertia::render('Public/RegistrationClosed', [
                'event' => $mostRecent ? [
                    'name' => $mostRecent->name,
                    'has_ended' => $mostRecent->ends_at?->isPast() ?? false,
                    'recording_url' => $mostRecent->ends_at?->isPast() ? $mostRecent->recording_url : null,
                ] : null,
            ]);
        }

        // An emailed invitation link (/attend?code=…) only ever pre-fills
        // the form: nothing is used up by a GET (link scanners prefetch).
        // Someone already registered redeems it from their account instead.
        $code = $this->prefillCode($request);
        $user = Auth::user();

        if ($code && $user && EventRegistration::where('event_id', $event->id)->where('user_id', $user->id)->exists()) {
            return redirect()->route('voucher.redeem', ['code' => $code]);
        }

        // A partner's referral link (/attend?ref={slug}) pre-selects that
        // partner under "How did you hear about us?"; remembered for the
        // session in case the visitor wanders off first. They can change it.
        $partners = $this->referralPartners($event);
        if (is_string($request->query('ref')) && $partners->firstWhere('partner.slug', $request->query('ref'))) {
            $request->session()->put('referral_partner_slug', $request->query('ref'));
        }
        $prefillPartner = $partners->firstWhere('partner.slug', $request->session()->get('referral_partner_slug'));

        return Inertia::render('Public/Register', [
            ...$this->formProps($event),
            'prefillVoucherCode' => $code,
            'prefillReferralPartnerId' => $prefillPartner['id'] ?? null,
            'authUser' => Auth::user() ? [
                'first_name' => Auth::user()->first_name,
                'last_name' => Auth::user()->last_name,
                'email' => Auth::user()->email,
                'mobile' => Auth::user()->mobile,
                'organisation' => Auth::user()->organisation,
                'job_title' => Auth::user()->job_title,
            ] : null,
        ]);
    }

    /**
     * The form for one event, independent of who's looking: shared with
     * the admin preview (Admin\RegistrationQuestionController::preview).
     */
    public function formProps(Event $event): array
    {
        return [
            'referralSources' => collect(config('seminar.referral_sources', []))
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'referralPartners' => $this->referralPartners($event)->map(fn ($row) => [
                'id' => $row['id'],
                'name' => $row['partner']->name,
                'can_share' => $row['can_share'],
                // Shown on the partner's tile; approved logos only.
                'logo_url' => $row['partner']->publicLogoUrl(),
                'logo_alt' => $row['partner']->logo_alt ?: $row['partner']->name,
            ])->values(),
            // The exact sentence (:partner is filled in on the page with the
            // chosen partner's name, the same way ConsentService does).
            'partnerConsentTemplate' => config('seminar.consent_wording.partner_contact.text'),
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'in_person_price' => (float) $event->in_person_price,
                // Off: step 3 offers in person only (the rules refuse online too).
                'offers_online' => $event->offersOnline(),
                // Display only: the registration side column shows when and where.
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'venue_name' => $event->venue_name,
                'venue_address' => $event->venue_address,
            ],
            // Steps 4 and 5: this event's own questions, per page.
            'questionPages' => $this->form->schema($event),
            'industryOptions' => config('seminar.industries'),
            'organisationSizeOptions' => config('seminar.organisation_sizes'),
        ];
    }

    /**
     * D-Registration — the stepper's per-step check. Same rule sets as
     * the final StoreRegistrationRequest (RegistrationRules::stepRules()),
     * just scoped to one step at a time; nothing is persisted here.
     */
    public function validateStep(Request $request): JsonResponse
    {
        $step = (int) $request->input('step');
        abort_unless($step >= 1 && $step <= 7, 422, 'Invalid step.');

        $data = $request->except('step');
        if (array_key_exists('email', $data)) {
            $data['email'] = strtolower(trim((string) $data['email']));
        }

        // Step 3 depends on whether the event offers online; steps 4 and 5
        // are the event's own questions.
        $currentEvent = in_array($step, [3, 4, 5], true) ? $this->currentRegistrationEvent() : null;
        $validator = Validator::make(
            $data,
            RegistrationRules::stepRules($step, $currentEvent),
            $currentEvent ? [...$this->form->messages($currentEvent), ...RegistrationRules::messages()] : RegistrationRules::messages(),
            $currentEvent ? $this->form->attributes($currentEvent) : [],
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Step 2 naming a partner: it must be one of this event's partners.
        if ($step === 2 && ($validator->validated()['referral_source'] ?? null) === 'partner') {
            $event = $this->currentRegistrationEvent();
            abort_unless($event !== null, 422, 'Registration is not currently open.');

            if (! $this->referralPartners($event)->firstWhere('id', (int) $validator->validated()['referral_partner_id'])) {
                return response()->json(['errors' => ['referral_partner_id' => ['Choose one of the partners listed.']]], 422);
            }
        }

        // Step 3 with an invitation code: check the code itself here, so a
        // typo shows up on the step where it was typed. Nothing is used up.
        $code = $validator->validated()['voucher_code'] ?? null;
        if ($step === 3 && filled($code)) {
            $event = $this->currentRegistrationEvent();
            abort_unless($event !== null, 422, 'Registration is not currently open.');

            try {
                $this->vouchers->resolve($code, $data['email'] ?? null, $event);
            } catch (ValidationException $e) {
                return response()->json(['errors' => $e->errors()], 422);
            }
        }

        return response()->json(['valid' => true]);
    }

    public function store(StoreRegistrationRequest $request): RedirectResponse
    {
        $event = $this->currentRegistrationEvent();
        abort_unless($event, 422, 'Registration is not currently open.');

        $data = $request->validated();
        $authUser = $request->user();

        // The partner named under "How did you hear about us?", which must
        // be one of this event's (attribution only unless they also ticked
        // the box to share their details).
        $data['referral_partner'] = null;
        if (($data['referral_source'] ?? null) === 'partner') {
            $data['referral_partner'] = $this->referralPartners($event)->firstWhere('id', (int) $data['referral_partner_id'])
                ?? throw ValidationException::withMessages(['referral_partner_id' => 'Choose one of the partners listed.']);
        }

        // Resolved before the account/guest split: whether a code is good
        // depends only on the code and the email typed, never on whether
        // that email has an account (B2).
        $voucher = filled($data['voucher_code'] ?? null)
            ? $this->vouchers->resolve($data['voucher_code'], $authUser->email ?? $data['email'], $event)
            : null;

        if ($authUser) {
            return $this->storeForAuthenticatedUser($event, $authUser, $data, $voucher);
        }

        return $this->storeForGuest($event, $data, $voucher);
    }

    private function storeForAuthenticatedUser(Event $event, User $user, array $data, ?Voucher $voucher): RedirectResponse
    {
        if (strcasecmp($user->email, $data['email']) !== 0) {
            return back()->withErrors([
                'email' => 'Please use your account email address while logged in.',
            ])->withInput();
        }

        // Mobile is optional: a blank one never erases a number already on
        // file (it can be cleared on the Account page).
        $user->fill([
            'mobile' => $data['mobile'] ?? $user->mobile,
            'organisation' => $data['organisation'],
            'job_title' => $data['job_title'],
        ])->save();

        $existing = EventRegistration::where('event_id', $event->id)->where('user_id', $user->id)->first();

        // Already registered (online, or in person and not yet paid) and
        // now holding an invitation code: the code upgrades that
        // registration. They're signed in, so a refusal is shown as is.
        if ($existing && $voucher) {
            $ticket = $this->vouchers->redeem($voucher, $existing, $user);
            $user->notify(new TicketConfirmed($ticket));

            return redirect()->route('ticket.show')
                ->with('success', 'Your invitation code has been applied — your complimentary ticket is confirmed.');
        }

        // B7 — re-registering while already registered no longer silently
        // no-ops; it routes them to the Account page, where consent
        // preferences can actually be changed.
        if ($existing) {
            return redirect()->route('attendee.preferences.edit')
                ->with('info', "You're already registered for this event — you can update your preferences below.");
        }

        DB::transaction(function () use ($event, $user, $data, $voucher) {
            $this->createRegistrationAndSideEffects($event, $user, $data, effectiveConsent: $user->hasVerifiedEmail(), voucher: $voucher);
            Legal::recordAcceptance($user, 'registration');
        });

        $registration = EventRegistration::where('event_id', $event->id)->where('user_id', $user->id)->first();
        if ($registration) {
            $user->notify(new RegistrationConfirmed($event, $registration));
        }

        if ($registration?->isComplimentary()) {
            return redirect()->route('ticket.show')
                ->with('success', "You're registered — your complimentary ticket is confirmed.");
        }

        return redirect()->route('event.register.confirmation');
    }

    private function storeForGuest(Event $event, array $data, ?Voucher $voucher): RedirectResponse
    {
        $email = $data['email'];

        DB::transaction(function () use ($event, $email, $data, $voucher) {
            $user = User::where('email', $email)->first();

            if ($user && $user->hasVerifiedEmail()) {
                $this->storeForExistingAccount($event, $user, $data, $voucher);

                return;
            }

            if (! $user) {
                $user = User::create([
                    'name' => trim($data['first_name'].' '.$data['last_name']),
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'role' => UserRole::Attendee,
                    'mobile' => $data['mobile'] ?? null,
                    'organisation' => $data['organisation'],
                    'job_title' => $data['job_title'],
                ]);
            } else {
                $user->fill([
                    'mobile' => $data['mobile'] ?? $user->mobile,
                    'organisation' => $data['organisation'],
                    'job_title' => $data['job_title'],
                ])->save();
            }

            $existing = EventRegistration::where('event_id', $event->id)->where('user_id', $user->id)->first();

            if (! $existing) {
                $this->createRegistrationAndSideEffects($event, $user, $data, effectiveConsent: false, voucher: $voucher);
            } elseif ($voucher) {
                // An earlier, never-confirmed registration for this email:
                // the code upgrades it. If this registration can't take it,
                // say nothing — the reason would describe the account to an
                // anonymous visitor (B2) — and the code stays unused.
                try {
                    $this->vouchers->redeem($voucher, $existing, $user);
                } catch (VoucherNotApplicable) {
                    // Deliberately silent; the response below is the same.
                }
            }

            // Only reached for a new or never-confirmed account. An existing
            // verified account's owner didn't necessarily make this
            // submission, so nothing is recorded against them above; they're
            // asked on their next sign-in instead (EnsureLegalAccepted).
            Legal::recordAcceptance($user, 'registration');

            // Resending this (e.g. the visitor resubmitted before clicking
            // the first link) is harmless — the broker's tokens are
            // single-use and this simply issues a fresh one.
            app(SendAccountSetupLink::class)->send($user, $event);
        });

        return redirect()->route('event.register.confirmation')
            ->with('info', 'Check your inbox to confirm your registration.');
    }

    /**
     * A guest submission whose email already has a confirmed account (owner
     * decision 2026-10-13: these used to be dropped, and people who thought
     * they had registered were missing from the attendee list).
     *
     * The registration is saved, but the submitter hasn't proved they own
     * the account, so: the profile is only filled where it is blank, no
     * consent takes effect until the owner next signs in, no terms are
     * recorded as accepted, and the owner is emailed with a link to cancel
     * if it wasn't them. Someone already registered is left exactly as they
     * are (and an invitation code stays unused): they only get the "you
     * already have an account" email.
     */
    private function storeForExistingAccount(Event $event, User $user, array $data, ?Voucher $voucher): void
    {
        if (EventRegistration::where('event_id', $event->id)->where('user_id', $user->id)->exists()) {
            $user->notify(new ExistingAccountRegistrationAttempt($event, hadInvitationCode: $voucher !== null));

            return;
        }

        $user->fill(array_filter([
            'mobile' => blank($user->mobile) ? ($data['mobile'] ?? null) : null,
            'organisation' => blank($user->organisation) ? $data['organisation'] : null,
            'job_title' => blank($user->job_title) ? $data['job_title'] : null,
        ], 'filled'))->save();

        $this->createRegistrationAndSideEffects($event, $user, $data, effectiveConsent: false, voucher: $voucher);

        $registration = EventRegistration::where('event_id', $event->id)->where('user_id', $user->id)->first();
        if (! $registration) {
            return;
        }

        Audit::record('registration.created_for_existing_account', subject: $registration, eventId: $event->id, after: [
            'attendance_mode' => $registration->attendance_mode->value,
            'complimentary' => $registration->isComplimentary(),
        ]);

        $user->notify(new RegistrationAddedToAccount($event, $registration));
    }

    private function createRegistrationAndSideEffects(Event $event, User $user, array $data, bool $effectiveConsent, ?Voucher $voucher = null): void
    {
        try {
            $registration = EventRegistration::create([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'attendance_mode' => $data['attendance_mode'],
                'industry' => $data['industry'] ?? null,
                'organisation_size' => $data['organisation_size'] ?? null,
                'status' => 'registered',
                'referral_source' => $data['referral_source'] ?? null,
                'referred_by_partner_id' => $data['referral_partner']['id'] ?? null,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // T-REG-5 — a concurrent submit already created the row.
                return;
            }

            throw $e;
        }

        // The event's own questions (steps 4 and 5), with snapshots; the
        // built-in ones also land in interests/improvement_area/challenge.
        $this->form->save($registration, $data['answers'] ?? []);

        if ($voucher) {
            // Complimentary (a code is only accepted with In person): the
            // payment is waived and the ticket confirmed straight away.
            $this->vouchers->redeem($voucher, $registration, $user);
        } elseif ($registration->attendance_mode->value === 'in_person') {
            app(StartInPersonPlace::class)->start($registration->setRelation('event', $event));
        }

        $ipAddress = request()->ip();
        $userAgent = substr((string) request()->userAgent(), 0, 500);

        if (! empty($data['networking_consent'])) {
            $this->consentService->grant(
                $user, ConsentType::Networking, $event,
                source: 'registration', ipAddress: $ipAddress, userAgent: $userAgent,
                effectiveImmediately: $effectiveConsent,
            );
        }

        if (! empty($data['lumko_marketing_consent'])) {
            $this->consentService->grant(
                $user, ConsentType::LumkoMarketing, null,
                source: 'registration', ipAddress: $ipAddress, userAgent: $userAgent,
                effectiveImmediately: $effectiveConsent,
            );
        }

        // Only if they ticked it, and only for a partner whose tier offers
        // leads; like the others, not effective until the email is verified.
        $referral = $data['referral_partner'] ?? null;
        if ($referral && ! empty($data['share_with_partner']) && $referral['can_share']) {
            $this->consentService->grant(
                $user, ConsentType::PartnerContact, $event,
                source: 'registration', ipAddress: $ipAddress, userAgent: $userAgent,
                effectiveImmediately: $effectiveConsent, partner: $referral['partner'],
            );
        }
    }

    /**
     * The partners offered under "How did you hear about us?": every
     * partner published on this event, whatever its tier (owner decision
     * 2026-10-08; the partner portal's Referrals page is still a tier
     * feature). `can_share` says whether the "share my details" box is
     * offered too (their tier takes leads).
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, partner: Partner, can_share: bool}>
     */
    private function referralPartners(Event $event)
    {
        return EventPartner::with(['partner', 'tier'])
            ->where('event_id', $event->id)
            ->where('is_published', true)
            ->get()
            ->map(fn (EventPartner $ep) => [
                'id' => $ep->partner_id,
                'partner' => $ep->partner,
                'can_share' => $ep->hasFeature(PartnerFeature::LeadsConnect),
            ])
            ->sortBy(fn ($row) => $row['partner']->name)
            ->values();
    }

    /** The ?code= of an invitation link, if it looks like one; never trusted beyond pre-filling. */
    private function prefillCode(Request $request): ?string
    {
        $code = $request->query('code');

        return is_string($code) && preg_match('/^[A-Za-z0-9 -]{1,32}$/', trim($code)) ? trim($code) : null;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000' && str_contains(strtolower($e->getMessage()), 'uniq');
    }

    private function currentRegistrationEvent(): ?Event
    {
        return RegistrationEvent::current();
    }

    public function confirmation(): Response
    {
        return Inertia::render('Public/RegisterConfirmation');
    }
}
