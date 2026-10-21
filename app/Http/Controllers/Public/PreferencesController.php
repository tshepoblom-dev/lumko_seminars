<?php

namespace App\Http\Controllers\Public;

use App\Actions\AccountAnonymiser;
use App\Enums\ConsentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\UpdatePreferencesRequest;
use App\Models\Connection;
use App\Models\ConsentRecord;
use App\Models\EventRegistration;
use App\Models\Feedback;
use App\Models\PartnerConnection;
use App\Models\PollResponse;
use App\Models\Question;
use App\Models\SpeakerConnection;
use App\Services\ConsentService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PreferencesController extends Controller
{
    public function __construct(private ConsentService $consent) {}

    public function edit(): Response
    {
        $user = Auth::user();
        $registration = $user->registrations()->latest()->first();

        return Inertia::render('Public/Preferences', [
            'profile' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'organisation' => $user->organisation,
                'job_title' => $user->job_title,
            ],
            'consents' => [
                // B7 — networking is per event; lumko_marketing is global.
                'networking' => $registration ? $this->consent->isGranted($user, ConsentType::Networking, $registration->event) : false,
                'lumko_marketing' => $this->consent->isGranted($user, ConsentType::LumkoMarketing),
            ],
            'hasRegistration' => (bool) $registration,
            // Speakers this person shares their details with, each
            // withdrawable here (POPIA: as easy to withdraw as to give).
            'speakerConnections' => SpeakerConnection::where('user_id', $user->id)->active()
                ->with(['speaker:id,name,organisation', 'event:id,name'])
                ->latest('granted_at')
                ->get()
                ->map(fn (SpeakerConnection $c) => [
                    'id' => $c->id,
                    'speaker' => $c->speaker?->name,
                    'organisation' => $c->speaker?->organisation,
                    'event' => $c->event?->name,
                    'since' => $c->granted_at?->format('j M Y'),
                ]),
            // Partners likewise (Connect, an enquiry, a stand scan, or at
            // registration), each withdrawable here.
            'partnerConnections' => PartnerConnection::where('user_id', $user->id)->active()
                ->with(['partner:id,name', 'event:id,name'])
                ->latest('granted_at')
                ->get()
                ->map(fn (PartnerConnection $c) => [
                    'id' => $c->id,
                    'partner' => $c->partner?->name,
                    'event' => $c->event?->name,
                    'how' => PartnerConnection::sourceLabel($c->source),
                    'since' => $c->granted_at?->format('j M Y'),
                ]),
        ]);
    }

    public function update(UpdatePreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'mobile' => $data['mobile'] ?? null,
            'organisation' => $data['organisation'],
            'job_title' => $data['job_title'],
        ])->save();

        $registration = $user->registrations()->latest()->first();
        $ip = $request->ip();
        $userAgent = substr((string) $request->userAgent(), 0, 500);

        // B7 — every grant/withdraw goes through the ledger (ConsentService),
        // never a direct AttendeeConsent write. Withdrawal is effective
        // immediately; a verified account's grant is too (registration-time
        // grants are the only ones that wait for email verification).
        if ($registration) {
            $wantsNetworking = (bool) ($data['networking_consent'] ?? false);
            $hasNetworking = $this->consent->isGranted($user, ConsentType::Networking, $registration->event);

            if ($wantsNetworking && ! $hasNetworking) {
                $this->consent->grant($user, ConsentType::Networking, $registration->event, source: 'account', ipAddress: $ip, userAgent: $userAgent, effectiveImmediately: true);
            } elseif (! $wantsNetworking && $hasNetworking) {
                $this->consent->withdraw($user, ConsentType::Networking, $registration->event, source: 'account', ipAddress: $ip, userAgent: $userAgent);
            }
        }

        $wantsMarketing = (bool) ($data['lumko_marketing_consent'] ?? false);
        $hasMarketing = $this->consent->isGranted($user, ConsentType::LumkoMarketing);

        if ($wantsMarketing && ! $hasMarketing) {
            $this->consent->grant($user, ConsentType::LumkoMarketing, null, source: 'account', ipAddress: $ip, userAgent: $userAgent, effectiveImmediately: true);
        } elseif (! $wantsMarketing && $hasMarketing) {
            $this->consent->withdraw($user, ConsentType::LumkoMarketing, null, source: 'account', ipAddress: $ip, userAgent: $userAgent);
        }

        return back()->with('success', 'Preferences updated.');
    }

    /**
     * D-Account — POPIA "access your data" request: everything this
     * application holds about the attendee, in one machine-readable file.
     * Self-service, so unlike the admin PII views this is never audited —
     * there's no one else to audit it against.
     */
    public function download(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'organisation' => $user->organisation,
                'job_title' => $user->job_title,
                'account_created_at' => $user->created_at->toIso8601String(),
            ],
            'registrations' => $user->registrations()->with(['event', 'payment', 'ticket', 'answers'])->get()->map(fn ($r) => [
                'event' => $r->event->name,
                'attendance_mode' => $r->attendance_mode->value,
                'industry' => $r->industry,
                'organisation_size' => $r->organisation_size,
                'interests' => $r->interests ?? [],
                'improvement_area' => $r->improvement_area ?? [],
                'organisation_challenge' => $r->organisation_challenge,
                // The event's own questions, as answered (a file by its name).
                'answers' => $r->answers->map(fn ($a) => ['question' => $a->question_label, 'answer' => $a->display])->values(),
                'status' => $r->status,
                'registered_at' => $r->created_at->toIso8601String(),
                'payment' => $r->payment ? ['status' => $r->payment->status->value, 'amount' => $r->payment->amount] : null,
                'ticket' => $r->ticket ? ['status' => $r->ticket->status->value, 'issued_at' => $r->ticket->issued_at?->toIso8601String()] : null,
            ]),
            'consent_history' => ConsentRecord::where('user_id', $user->id)->orderBy('created_at')->get()->map(fn ($c) => [
                'type' => $c->type, 'action' => $c->action, 'purpose_text' => $c->purpose_text,
                'source' => $c->source, 'effective' => $c->effective_at !== null, 'recorded_at' => $c->created_at->toIso8601String(),
            ]),
            'feedback_submitted' => Feedback::where('user_id', $user->id)->get()->map(fn ($f) => [
                'event_id' => $f->event_id, 'rating' => $f->rating, 'biggest_takeaway' => $f->biggest_takeaway,
                'topics_next' => $f->topics_next ?? [], 'wants_contact' => $f->wants_contact, 'submitted_at' => $f->created_at->toIso8601String(),
            ]),
            'questions_asked' => Question::where('user_id', $user->id)->get()->map(fn ($q) => [
                'event_id' => $q->event_id, 'body' => $q->body, 'status' => $q->status->value, 'asked_at' => $q->created_at->toIso8601String(),
            ]),
            'poll_responses' => PollResponse::where('user_id', $user->id)->with('option')->get()->map(fn ($r) => [
                'poll_id' => $r->poll_id, 'option' => $r->option?->label,
            ]),
            'networking_connections' => Connection::where('requester_id', $user->id)->orWhere('recipient_id', $user->id)
                ->with(['requester', 'recipient'])->get()->map(fn ($c) => [
                    'event_id' => $c->event_id,
                    'with' => ($c->requester_id === $user->id ? $c->recipient?->name : $c->requester?->name),
                    'status' => $c->status->value,
                    'direction' => $c->requester_id === $user->id ? 'sent' : 'received',
                ]),
            'speaker_connections' => SpeakerConnection::where('user_id', $user->id)->with(['speaker', 'event'])->get()->map(fn ($c) => [
                'event' => $c->event?->name,
                'speaker' => $c->speaker?->name,
                'note' => $c->note,
                'shared_since' => $c->granted_at?->toIso8601String(),
                'withdrawn_at' => $c->revoked_at?->toIso8601String(),
            ]),
            'partner_connections' => PartnerConnection::where('user_id', $user->id)->with(['partner', 'event'])->get()->map(fn ($c) => [
                'event' => $c->event?->name,
                'partner' => $c->partner?->name,
                'how' => PartnerConnection::sourceLabel($c->source),
                'note' => $c->note,
                'shared_since' => $c->granted_at?->toIso8601String(),
                'withdrawn_at' => $c->revoked_at?->toIso8601String(),
            ]),
            'how_you_heard' => EventRegistration::where('user_id', $user->id)->with('referredByPartner:id,name')->get()
                ->filter(fn ($r) => $r->referral_source)
                ->map(fn ($r) => [
                    'event_id' => $r->event_id,
                    'answer' => $r->referral_source === 'partner'
                        ? ($r->referredByPartner?->name ?? 'A partner')
                        : (config('seminar.referral_sources')[$r->referral_source] ?? $r->referral_source),
                ])->values(),
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="my-data-'.now()->format('Y-m-d').'.json"',
        ]);
    }

    /**
     * B12 — "delete my account" is anonymisation, requires the current
     * password, and ends the session immediately.
     */
    public function destroy(Request $request, AccountAnonymiser $anonymiser): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();

        $anonymiser->anonymise($user, actor: $user);

        Auth::guard('web')->logout();
        event(new Logout('web', $user));

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Your account has been deleted.');
    }
}
