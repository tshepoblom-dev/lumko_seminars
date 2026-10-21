<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;

/**
 * B1 — replaces the scattered `registrations()->latest()->first()` and
 * `Event::published()->upcomingOrCurrent()->first()` calls that used to be
 * copy-pasted across controllers with one auditable place to change the
 * rule (e.g. when a second event goes live).
 */
class EventResolver
{
    /**
     * The public event attendees currently interact with: published, and
     * either upcoming, currently running, or ended within the configured
     * post-event window (so the portal/resources/feedback keep working for
     * a while after the event, per seminar.post_event_days).
     */
    public function currentPublicEvent(): ?Event
    {
        return Event::publiclyVisible()
            ->where('ends_at', '>=', now()->subDays((int) config('seminar.post_event_days', 90)))
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * The event a specific user is registered for and should see across
     * the attendee portal (live, resources, networking, feedback...).
     * Staff have no "current event" via this method — they act on
     * whichever event a route parameter names, scoped by assignment.
     */
    public function forUser(User $user): ?Event
    {
        $registration = $this->registrationFor($user);

        return $registration?->event;
    }

    public function registrationFor(User $user): ?EventRegistration
    {
        $event = $this->currentPublicEvent();

        if (! $event) {
            return null;
        }

        return EventRegistration::where('user_id', $user->id)
            ->where('event_id', $event->id)
            ->where('status', 'registered')
            ->first();
    }
}
