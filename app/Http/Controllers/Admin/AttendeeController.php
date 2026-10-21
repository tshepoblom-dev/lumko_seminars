<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendAccountSetupLink;
use App\Enums\ConsentType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Responses\SafeFileResponse;
use App\Models\ClientConversion;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Partner;
use App\Models\PollResponse;
use App\Models\QuestionVote;
use App\Models\RegistrationAnswer;
use App\Models\RegistrationQuestion;
use App\Models\ResourceView;
use App\Models\User;
use App\Notifications\RegisterForEventReminder;
use App\Services\ConsentService;
use App\Services\RegistrationForm;
use App\Support\Audit;
use App\Support\Csv;
use App\Support\RegistrantFilter;
use App\Support\RegistrationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * D-Admin attendee management (spec §21) — factual, not scored: labels
 * like "Lumko communication opted in" or "Requested follow-up" only, no
 * "hot lead" language or engagement scoring. Every profile view here is
 * a PII view and is audited (B13).
 */
class AttendeeController extends Controller
{
    private const FILTERS = RegistrantFilter::KEYS;

    /** sent_notifications.type for the "you haven't registered yet" nudge. */
    private const REGISTER_REMINDER = 'register_reminder';

    private const ACCOUNTS_ONLY_SHOWN = 50;

    public function __construct(private ConsentService $consents) {}

    public function index(Request $request, Event $event): Response
    {
        $this->authorize('viewAny', EventRegistration::class);
        $this->authorize('view', $event);

        $registrations = $this->filteredQuery($request, $event)->latest()->paginate(25)->withQueryString();

        Audit::record('attendees.list_viewed', eventId: $event->id, after: ['filters' => $request->only(self::FILTERS)]);

        return Inertia::render('Admin/Attendees/Index', [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'filters' => $request->only(self::FILTERS),
            'industries' => config('seminar.industries'),
            // The event's own interests and choice/yes-no questions, as filters.
            'formFilters' => app(RegistrationForm::class)->filterOptions($event),
            'total' => $registrations->total(),
            'notSetUpCount' => $this->notSetUp($event)->count(),
            'accountsOnly' => $this->accountsOnlyProps($event),
            'attendees' => $registrations->through(fn (EventRegistration $registration) => [
                'id' => $registration->id,
                'user_id' => $registration->user_id,
                'public_id' => $registration->public_id,
                'name' => $registration->user->name,
                'email' => $registration->user->email,
                'organisation' => $registration->user->organisation,
                'attendance_mode' => $registration->attendance_mode->label(),
                'payment_status' => self::paymentLabel($registration),
                'payment_status_raw' => self::isComplimentarySeat($registration) ? 'complimentary' : $registration->payment?->status?->value,
                'checked_in' => $registration->ticket?->checkIn !== null,
                'account_set_up' => $registration->user->hasVerifiedEmail(),
            ]),
        ]);
    }

    /**
     * The attendee list's filters, shared by the list, the CSV export and
     * the communications composer (RegistrantFilter), so a download or an
     * email always reaches exactly the rows on screen.
     */
    private function filteredQuery(Request $request, Event $event)
    {
        $query = EventRegistration::where('event_id', $event->id)
            ->with(['user', 'payment', 'ticket.checkIn']);

        return RegistrantFilter::apply($query, RegistrantFilter::sanitize($request->only(self::FILTERS), $event), $event);
    }

    /**
     * Every answer from the registration form, for the attendees matching
     * the current filters. A PII export, so it is audited like a list view.
     */
    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->authorize('viewAny', EventRegistration::class);
        $this->authorize('view', $event);

        $query = $this->filteredQuery($request, $event);
        $filters = $request->only(self::FILTERS);
        $filename = 'attendees-'.($event->slug ?: $event->id).'-'.now()->format('Y-m-d').'.csv';

        Audit::record('attendees.exported', eventId: $event->id, after: [
            'filters' => $filters,
            'rows' => (clone $query)->count(),
        ]);

        // The event's own questions (not the three standard ones, which
        // have their columns already), including switched-off ones that
        // were answered. Text answers as given; a file by its name.
        $extraQuestions = app(RegistrationForm::class)->questions($event, activeOnly: false)
            ->filter(fn (RegistrationQuestion $q) => ! $q->isBuiltIn())->values();

        return response()->streamDownload(function () use ($query, $event, $extraQuestions) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly

            fputcsv($out, array_map([self::class, 'csvSafe'], [
                'First name', 'Last name', 'Email', 'Mobile', 'Organisation', 'Job title',
                'Attendance', 'Industry', 'Organisation size', 'Interests', 'Wants to improve',
                'Organisation challenge', 'Registered at', 'Account set up', 'Payment status',
                'Checked in', 'Networking opt-in', 'Lumko opt-in',
                ...$extraQuestions->pluck('label')->all(),
            ]));

            $query->with('answers')->chunkById(200, function ($registrations) use ($out, $event, $extraQuestions) {
                foreach ($registrations as $registration) {
                    $user = $registration->user;
                    $answers = $registration->answers->keyBy('registration_question_id');

                    fputcsv($out, array_map([self::class, 'csvSafe'], [
                        $user->first_name,
                        $user->last_name,
                        $user->email,
                        $user->mobile,
                        $user->organisation,
                        $user->job_title,
                        $registration->attendance_mode->label(),
                        $registration->industry,
                        $registration->organisation_size,
                        implode('; ', $registration->interests ?? []),
                        implode('; ', $registration->improvement_area ?? []),
                        $registration->organisation_challenge,
                        $registration->created_at->format('Y-m-d H:i'),
                        $user->hasVerifiedEmail() ? 'Yes' : 'No',
                        self::paymentLabel($registration),
                        $registration->ticket?->checkIn ? 'Yes' : 'No',
                        $this->consents->isGranted($user, ConsentType::Networking, $event) ? 'Yes' : 'No',
                        $this->consents->isGranted($user, ConsentType::LumkoMarketing) ? 'Yes' : 'No',
                        ...$extraQuestions->map(fn ($q) => $answers->get($q->id)?->display)->all(),
                    ]));
                }
            }, 'event_registrations.id', 'id');

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A file an attendee uploaded with a registration answer. Staff only
     * (the admin route group), always a download, audited like a proof of
     * payment view.
     */
    public function answerFile(Event $event, EventRegistration $registration, RegistrationAnswer $answer): StreamedResponse
    {
        $this->authorize('view', $registration);
        abort_unless($registration->event_id === $event->id && $answer->event_registration_id === $registration->id, 404);

        $path = $answer->filePath();
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        Audit::record('registration_answer.file_downloaded', subject: $registration, eventId: $event->id, after: [
            'answer_id' => $answer->id,
        ]);

        return SafeFileResponse::make('local', $path, $answer->value['name'] ?? 'answer');
    }

    /**
     * CSV/formula injection guard: free-text answers come from the public,
     * so a cell a spreadsheet would treat as a formula is prefixed with '.
     */
    private static function csvSafe(mixed $value): string
    {
        return Csv::safe($value);
    }

    /** An in-person seat waived with an invitation code (Event → Vouchers), still standing. */
    private static function isComplimentarySeat(EventRegistration $registration): bool
    {
        return $registration->isComplimentary() && $registration->payment?->status === PaymentStatus::Approved;
    }

    private static function paymentLabel(EventRegistration $registration): ?string
    {
        return self::isComplimentarySeat($registration) ? 'Complimentary' : $registration->payment?->status?->label();
    }

    /**
     * Re-sends the "confirm your email and set a password" link to one
     * registrant whose account isn't set up yet (e.g. the first email
     * never arrived, or its 24-hour link expired).
     */
    public function sendSetupLink(Event $event, EventRegistration $registration, SendAccountSetupLink $setupLink): RedirectResponse
    {
        $this->authorize('update', $event);

        $user = $registration->user;

        if (! $setupLink->send($user, $event)) {
            return back()->with('info', "{$user->name} has already set up their account.");
        }

        Audit::record('attendee.setup_link_sent', subject: $registration, eventId: $event->id);

        return back()->with('success', "Setup link sent to {$user->email}.");
    }

    /**
     * Sends the setup link to every registrant of this event whose
     * account still isn't set up.
     */
    public function sendSetupLinks(Event $event, SendAccountSetupLink $setupLink): RedirectResponse
    {
        $this->authorize('update', $event);

        $sent = 0;
        $this->notSetUp($event)->with('user')->chunkById(100, function ($registrations) use ($event, $setupLink, &$sent) {
            foreach ($registrations as $registration) {
                $sent += $setupLink->send($registration->user, $event) ? 1 : 0;
            }
        });

        Audit::record('attendees.setup_links_sent', eventId: $event->id, after: ['count' => $sent]);

        return back()->with(
            $sent ? 'success' : 'info',
            $sent ? "Setup links sent to {$sent} registrant(s)." : 'Everyone registered has already set up their account.'
        );
    }

    /** Registrations whose account has never been confirmed. */
    private function notSetUp(Event $event)
    {
        return EventRegistration::where('event_id', $event->id)
            ->whereHas('user', fn ($q) => $q->whereNull('email_verified_at')->where('email', 'not like', '%@invalid'));
    }

    /**
     * People with an attendee account who aren't registered for this event
     * (they signed up but never finished the event form, or came to an
     * earlier event). They are not attendees, so they're not in the list
     * above; without this they are invisible to staff. Speakers, partner
     * team members and anonymised accounts are left out.
     */
    private function accountsOnly(Event $event)
    {
        return User::query()
            ->where('role', UserRole::Attendee)
            ->where('email', 'not like', '%@invalid')
            ->whereDoesntHave('registrations', fn ($q) => $q->where('event_id', $event->id))
            ->whereDoesntHave('speakerProfile')
            ->whereDoesntHave('partners');
    }

    private function accountsOnlyProps(Event $event): array
    {
        $users = $this->accountsOnly($event)->latest('id')->limit(self::ACCOUNTS_ONLY_SHOWN)->get();

        $reminded = DB::table('sent_notifications')
            ->where('event_id', $event->id)
            ->where('type', self::REGISTER_REMINDER)
            ->whereIn('user_id', $users->pluck('id'))
            ->pluck('sent_at', 'user_id');

        return [
            'count' => $this->accountsOnly($event)->count(),
            // Reminders point at /attend, which serves one event at a time.
            'can_remind' => RegistrationEvent::current()?->id === $event->id,
            'rows' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'signed_up_on' => $user->created_at?->format('j M Y'),
                'email_confirmed' => $user->hasVerifiedEmail(),
                'reminded_on' => isset($reminded[$user->id]) ? Carbon::parse($reminded[$user->id])->format('j M Y') : null,
            ])->values(),
        ];
    }

    /** Emails one account holder a nudge to register for this event. */
    public function remindToRegister(Event $event, User $user): RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($this->accountsOnly($event)->whereKey($user->id)->exists(), 404);
        abort_unless(RegistrationEvent::current()?->id === $event->id, 422, 'Registration for this event is not open.');

        $this->sendRegisterReminder($event, $user);

        Audit::record('attendees.register_reminder_sent', subject: $user, eventId: $event->id, after: ['count' => 1]);

        return back()->with('success', "Reminder to register sent to {$user->email}.");
    }

    /**
     * The same nudge to everyone with an account and no registration for
     * this event, skipping anyone reminded in the last 24 hours.
     */
    public function remindAllToRegister(Event $event): RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless(RegistrationEvent::current()?->id === $event->id, 422, 'Registration for this event is not open.');

        $recentlyReminded = DB::table('sent_notifications')
            ->where('event_id', $event->id)
            ->where('type', self::REGISTER_REMINDER)
            ->where('sent_at', '>', now()->subDay())
            ->pluck('user_id');

        $sent = 0;
        $this->accountsOnly($event)->whereNotIn('id', $recentlyReminded)->chunkById(100, function ($users) use ($event, &$sent) {
            foreach ($users as $user) {
                $this->sendRegisterReminder($event, $user);
                $sent++;
            }
        });

        Audit::record('attendees.register_reminder_sent', eventId: $event->id, after: ['count' => $sent]);

        return back()->with(
            $sent ? 'success' : 'info',
            $sent
                ? "Reminder to register sent to {$sent} ".($sent === 1 ? 'person' : 'people').'.'
                : 'Everyone without a registration was already reminded in the last 24 hours.'
        );
    }

    private function sendRegisterReminder(Event $event, User $user): void
    {
        $user->notify(new RegisterForEventReminder($event));

        DB::table('sent_notifications')->updateOrInsert(
            ['user_id' => $user->id, 'event_id' => $event->id, 'type' => self::REGISTER_REMINDER],
            ['sent_at' => now()],
        );
    }

    public function show(Event $event, EventRegistration $registration): Response
    {
        $this->authorize('view', $registration);
        abort_unless($registration->event_id === $event->id, 404);

        $registration->load(['user', 'payment', 'ticket.checkIn.operator', 'referredByPartner']);
        $user = $registration->user;

        Audit::record('attendee.profile_viewed', subject: $registration, eventId: $event->id);

        return Inertia::render('Admin/Attendees/Show', [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'registrationId' => $registration->id,
            'attendee' => [
                'name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'organisation' => $user->organisation,
                'job_title' => $user->job_title,
                'account_set_up' => $user->hasVerifiedEmail(),
                'can_send_setup_link' => ! $user->hasVerifiedEmail() && ! SendAccountSetupLink::isTombstone($user->email),
            ],
            'registration' => [
                'attendance_mode' => $registration->attendance_mode->label(),
                'industry' => $registration->industry,
                'organisation_size' => $registration->organisation_size,
                'interests' => $registration->interests ?? [],
                'improvement_area' => $registration->improvement_area ?? [],
                'organisation_challenge' => $registration->organisation_challenge,
                'registered_at' => $registration->created_at->format('d M Y, H:i'),
            ],
            'payment' => $registration->payment ? [
                'status' => self::paymentLabel($registration),
                'amount' => $registration->payment->amount,
                'complimentary' => self::isComplimentarySeat($registration),
            ] : null,
            'ticket' => $registration->ticket ? [
                'status' => $registration->ticket->status->value,
                'checked_in' => $registration->ticket->checkIn !== null,
                'checked_in_at' => $registration->ticket->checkIn?->checked_in_at?->format('d M Y, H:i'),
                'checked_in_by' => $registration->ticket->checkIn?->operator?->name,
            ] : null,
            'consentTimeline' => ConsentRecord::where('user_id', $user->id)
                ->where(fn ($q) => $q->where('event_id', $event->id)->orWhereNull('event_id'))
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (ConsentRecord $record) => [
                    'type' => $record->type,
                    'action' => $record->action,
                    'purpose_text' => $record->purpose_text,
                    'source' => $record->source,
                    'effective' => $record->effective_at !== null,
                    'created_at' => $record->created_at->format('d M Y, H:i'),
                ]),
            'engagement' => [
                'questions_submitted' => $event->questions()->where('user_id', $user->id)->count(),
                'question_votes_cast' => QuestionVote::whereHas('question', fn ($q) => $q->where('event_id', $event->id))
                    ->where('user_id', $user->id)->count(),
                'poll_responses' => PollResponse::whereHas('poll', fn ($q) => $q->where('event_id', $event->id))
                    ->where('user_id', $user->id)->count(),
                'resource_views' => ResourceView::whereHas('resource', fn ($q) => $q->where('event_id', $event->id))
                    ->where('user_id', $user->id)->count(),
            ],
            // Answers to the event's own registration questions, as given.
            'answers' => $registration->answers()->with('question')->get()
                ->sortBy(fn ($a) => [$a->question?->page, $a->question?->sort_order, $a->id])
                ->values()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'question' => $a->question_label,
                    'display' => $a->display,
                    'is_file' => $a->filePath() !== null,
                ]),
            // "How did you hear about us?" and whether they became a client
            // (crediting a partner, pre-filled from their latest referral).
            'referral' => [
                'source' => $registration->referral_source,
                'label' => $registration->referral_source === 'partner'
                    ? $registration->referredByPartner?->name
                    : (config('seminar.referral_sources')[$registration->referral_source] ?? null),
            ],
            'conversion' => ($c = ClientConversion::where('user_id', $user->id)->first()) ? [
                'converted_on' => $c->converted_on?->toDateString(),
                'partner_id' => $c->partner_id,
                'deal_value' => $c->deal_value_cents !== null ? $c->deal_value_cents / 100 : null,
                'notes' => $c->notes,
            ] : null,
            'suggestedPartnerId' => EventRegistration::where('user_id', $user->id)->whereNotNull('referred_by_partner_id')
                ->latest()->value('referred_by_partner_id'),
            'partnerOptions' => Partner::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
