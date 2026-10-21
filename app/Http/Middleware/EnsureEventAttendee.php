<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\Feedback;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Question;
use App\Models\Resource;
use App\Support\EventResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * B1 — the single gate in front of every attendee-only feature: /live,
 * the Agora token, questions, polls, reactions, resources and networking.
 * Requires: authenticated, email verified, a `registered` EventRegistration
 * for the resolved event, and the event still published. Staff pass
 * through automatically (they use these pages to preview/support).
 *
 * Route-bound models that carry their own `event_id`/`event()` relation are
 * used to resolve "the event" for that request; routes with no such model
 * (e.g. GET /live, /resources, /networking, /feedback) fall back to
 * EventResolver::currentPublicEvent(). A route naming a real event/model
 * that the attendee is not registered for returns 403 (they exist, you
 * can't); a route parameter that doesn't resolve to any model at all is a
 * plain 404 from route-model binding before this middleware ever runs.
 */
class EnsureEventAttendee
{
    public function __construct(private EventResolver $events) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user, 401);

        [$event, $explicit] = $this->resolveEvent($request);

        if ($user->isStaff()) {
            // Staff bypass the registration check but still get the
            // resolved event on the request, so controllers built to read
            // `currentEvent`/`currentRegistration` (e.g. FeedbackController)
            // can give staff a clean 404/preview instead of a null-property
            // error. `currentRegistration` is deliberately left unset —
            // staff never have one of their own.
            if ($event) {
                $request->attributes->set('currentEvent', $event);
            }

            return $next($request);
        }

        abort_unless($user->hasVerifiedEmail(), 403, 'Please confirm your email address first.');

        // B1/T-ACL-2 — a route that names a specific question/poll/resource
        // id belongs to a real record; if that record's event isn't the
        // one this user is registered for, we must not confirm the id
        // exists by returning 403. A generic route with no id (e.g. GET
        // /live falling back to the current public event) has nothing to
        // leak, so "you're not registered" as 403 is fine there.
        $notFoundStatus = $explicit ? 404 : 403;

        abort_unless($event, 404);
        abort_unless(in_array($event->status, ['published', 'completed'], true), 404);

        $registration = $user->registrations()
            ->where('event_id', $event->id)
            ->where('status', 'registered')
            ->first();

        // A speaker on this event's line-up (signed in through the speaker
        // portal) needs no registration to watch and take part. Like staff,
        // they get no `currentRegistration`.
        if (! $registration && $this->speaksAt($user, $event)) {
            $request->attributes->set('currentEvent', $event);

            return $next($request);
        }

        abort_unless($registration, $notFoundStatus, 'You are not registered for this event.');

        $request->attributes->set('currentEvent', $event);
        $request->attributes->set('currentRegistration', $registration);

        return $next($request);
    }

    private function speaksAt($user, Event $event): bool
    {
        $speaker = $user->speakerProfile;

        return $speaker !== null && $event->speakers()->whereKey($speaker->id)->exists();
    }

    /**
     * @return array{0: ?Event, 1: bool} the resolved event, and whether it
     *                                   was resolved from an explicit route-bound model (true) or the
     *                                   generic current-public-event fallback (false).
     */
    private function resolveEvent(Request $request): array
    {
        $route = $request->route();

        if (! $route) {
            return [$this->events->currentPublicEvent(), false];
        }

        foreach ($route->parameters() as $parameter) {
            $event = match (true) {
                $parameter instanceof Event => $parameter,
                $parameter instanceof Question => $parameter->event,
                $parameter instanceof Poll => $parameter->event,
                $parameter instanceof PollOption => $parameter->poll?->event,
                $parameter instanceof Resource => $parameter->event,
                $parameter instanceof Feedback => $parameter->event,
                default => null,
            };

            if ($event) {
                return [$event, true];
            }
        }

        return [$this->events->currentPublicEvent(), false];
    }
}
