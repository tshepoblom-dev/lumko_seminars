<?php

namespace App\Http\Controllers\Public;

use App\Enums\ConsentType;
use App\Http\Controllers\Controller;
use App\Models\ProgrammeSession;
use App\Models\Resource;
use App\Models\SpeakerConnection;
use App\Services\ConsentService;
use App\Support\JourneyBuilder;
use App\Support\PartnerListing;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AttendeeDashboardController extends Controller
{
    public function __construct(private ConsentService $consentService) {}

    public function index(): Response
    {
        $registration = Auth::user()->registrations()
            ->with(['event.programmeSessions.speakers', 'event.speakers', 'event.currentSession', 'payment', 'ticket'])
            ->latest()
            ->first();

        if (! $registration) {
            return Inertia::render('Public/DashboardNoRegistration');
        }

        $event = $registration->event;
        $now = now();
        $hasEnded = $event->ends_at?->lte($now) ?? false;

        $steps = JourneyBuilder::build($registration, $now);

        $connectedSpeakerIds = SpeakerConnection::where('user_id', Auth::id())
            ->where('event_id', $event->id)
            ->active()
            ->pluck('speaker_id');

        return Inertia::render('Public/Dashboard', [
            'registration' => [
                'attendance_mode' => $registration->attendance_mode->value,
                'attendance_mode_label' => $registration->attendance_mode->label(),
                'complimentary' => $registration->isComplimentary(),
                // An online registration for an event that became in person
                // only: offered the switch (SwitchToInPersonController).
                'can_switch_to_in_person' => $registration->attendance_mode->value === 'online'
                    && ! $hasEnded
                    && ! $event->offersOnline()
                    && ! $registration->payment,
            ],
            'event' => [
                'name' => $event->name,
                'starts_at' => $event->starts_at->toIso8601String(),
                'venue_name' => $event->venue_name,
                'in_person_price' => (float) $event->in_person_price,
                'offers_online' => $event->offersOnline(),
                'has_ended' => $hasEnded,
                'is_live' => $event->is_live,
                'within_live_window' => $event->isWithinLiveLeadWindow(),
                'opens_at' => $event->liveOpensAt()?->toIso8601String(),
                'recording_url' => $hasEnded ? $event->recording_url : null,
            ],
            'payment' => $registration->payment ? [
                'status' => $registration->payment->status->value,
                'status_label' => $registration->payment->status->label(),
            ] : null,
            'ticket' => $registration->ticket ? [
                'status' => $registration->ticket->status->value,
            ] : null,
            'journey' => [
                'steps' => $steps,
                'nextStep' => JourneyBuilder::nextStep($steps),
            ],
            'programme' => $event->programmeSessions->map(fn ($session) => [
                'title' => $session->title,
                'description' => $session->description,
                'start_time' => $session->start_time?->format('H:i'),
                'end_time' => $session->end_time?->format('H:i'),
                'speaker_name' => ProgrammeSession::speakerLine($session),
            ]),
            'speakers' => $event->speakers->map(fn ($speaker) => [
                'id' => $speaker->id,
                'name' => $speaker->name,
                'title' => $speaker->title,
                'organisation' => $speaker->organisation,
                'photo_url' => $speaker->photo_approved && $speaker->photo_path ? asset('storage/'.$speaker->photo_path) : null,
                'connected' => $connectedSpeakerIds->contains($speaker->id),
                // The exact sentence the attendee agrees to (and the ledger stores).
                'consent_text' => $this->consentService->wording(ConsentType::SpeakerContact, $speaker)[1],
            ]),
            'speakerConnect' => [
                'event_id' => $event->id,
                'open' => $registration->status === 'registered' && $event->acceptsSpeakerConnections(),
            ],
            // Partners by tier; those with a partner page link to it.
            'partners' => PartnerListing::forEvent($event, linkProfiles: $registration->status === 'registered'),
            'resourcesAvailableCount' => Resource::availableFor($event, $event->currentSession, $now)->count(),
            'networkingOptedIn' => $this->consentService->isGranted(Auth::user(), ConsentType::Networking, $event),
            'announcements' => $event->announcements()
                ->published()
                ->latest('published_at')
                ->get()
                ->map(fn ($a) => [
                    'title' => $a->title,
                    'body' => $a->body,
                    'type_label' => $a->type->label(),
                    'published_at' => $a->published_at->format('d M Y, H:i'),
                ]),
        ]);
    }
}
