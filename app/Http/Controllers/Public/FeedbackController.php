<?php

namespace App\Http\Controllers\Public;

use App\Enums\ConsentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreFeedbackRequest;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Feedback;
use App\Models\Speaker;
use App\Models\SpeakerConnection;
use App\Services\ConsentService;
use App\Services\RegistrationForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class FeedbackController extends Controller
{
    public function __construct(private ConsentService $consentService) {}

    public function create(Request $request): Response
    {
        $registration = $this->registration($request);
        $event = $registration->event;

        $existing = Feedback::where('event_id', $event->id)->where('user_id', Auth::id())->first();

        return Inertia::render('Public/Feedback', [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'topicOptions' => app(RegistrationForm::class)->topicOptions($event),
            'alreadySubmitted' => (bool) $existing,
            'isOpen' => $this->isFeedbackWindowOpen($event),
            'speakers' => $this->speakerChoices($event),
        ]);
    }

    /** The event's speakers, each with the exact consent sentence and whether it's already given. */
    private function speakerChoices(Event $event): array
    {
        $connected = SpeakerConnection::where('user_id', Auth::id())->where('event_id', $event->id)
            ->active()->pluck('speaker_id');

        return $event->speakers()->get()->map(fn (Speaker $speaker) => [
            'id' => $speaker->id,
            'name' => $speaker->name,
            'organisation' => $speaker->organisation,
            'connected' => $connected->contains($speaker->id),
            'consent_text' => $this->consentService->wording(ConsentType::SpeakerContact, $speaker)[1],
        ])->all();
    }

    public function store(StoreFeedbackRequest $request): RedirectResponse
    {
        $registration = $this->registration($request);
        $event = $registration->event;

        // B10 — the client never supplies event_id; it's always the
        // caller's own registration's event, checked here again (not
        // just trusted from the `attendee` middleware) since the feedback
        // window itself is a separate business rule.
        abort_unless($this->isFeedbackWindowOpen($event), 422, 'Feedback is not open for this event.');

        Feedback::updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $request->user()->id],
            $request->safe()->only(['rating', 'biggest_takeaway', 'topics_next', 'wants_contact', 'contact_topics'])
        );

        if ($request->boolean('wants_contact')) {
            $this->consentService->grant(
                $request->user(), ConsentType::FollowUpRequest, $event,
                source: 'feedback', ipAddress: $request->ip(), userAgent: substr((string) $request->userAgent(), 0, 500),
                effectiveImmediately: true,
            );
        }

        // Speakers ticked here: one consent per speaker, only for this
        // event's speakers, and never twice for one already connected.
        // (Unticking isn't a withdrawal; that's done from /account.)
        $speakerIds = array_map('intval', $request->validated('speaker_ids') ?? []);
        if ($speakerIds) {
            foreach ($event->speakers()->whereIn('speakers.id', $speakerIds)->get() as $speaker) {
                if ($this->consentService->isGranted($request->user(), ConsentType::SpeakerContact, $event, $speaker)) {
                    continue;
                }

                $this->consentService->grant(
                    $request->user(), ConsentType::SpeakerContact, $event,
                    source: 'feedback', ipAddress: $request->ip(), userAgent: substr((string) $request->userAgent(), 0, 500),
                    effectiveImmediately: true, speaker: $speaker,
                );
            }
        }

        return redirect()->route('feedback.thanks');
    }

    public function thanks(Request $request): Response
    {
        $registration = $this->registration($request);

        $otherPublishedEvents = Event::published()
            ->where('id', '!=', $registration->event_id)
            ->orderBy('starts_at')
            ->get(['name', 'starts_at'])
            ->map(fn ($e) => ['name' => $e->name, 'starts_at' => $e->starts_at->format('d M Y')]);

        return Inertia::render('Public/FeedbackThanks', [
            'otherPublishedEvents' => $otherPublishedEvents,
        ]);
    }

    private function registration(Request $request): EventRegistration
    {
        // Set by EnsureEventAttendee for a normal attendee; staff previewing
        // these pages have no "own" registration, so fail clearly rather
        // than with a null-property error.
        $registration = $request->attributes->get('currentRegistration');
        abort_unless($registration instanceof EventRegistration, 404);

        return $registration;
    }

    private function isFeedbackWindowOpen(Event $event): bool
    {
        if (! $event->ends_at) {
            return false;
        }

        if ($event->ends_at->isFuture()) {
            return false;
        }

        return $event->ends_at->copy()->addDays((int) config('seminar.feedback_days', 30))->isFuture();
    }
}
