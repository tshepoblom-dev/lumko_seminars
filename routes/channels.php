<?php

use Illuminate\Support\Facades\Broadcast;

/**
 * B6/T-RT-3 — event.{id} requires a *verified*, registered attendee of
 * that event, or staff assigned to it. admin.event.{id} requires staff
 * assigned to that specific event (not just any staff member) — B11's
 * event-manager scoping applies to the control-room channel too.
 */
Broadcast::channel('event.{eventId}', function ($user, $eventId) {
    if ($user->isStaff()) {
        return $user->isSuperAdmin() || $user->managedEvents()->where('events.id', $eventId)->exists();
    }

    return $user->hasVerifiedEmail()
        && $user->registrations()->where('event_id', $eventId)->where('status', 'registered')->exists();
});

Broadcast::channel('admin.event.{eventId}', function ($user, $eventId) {
    return $user->isStaff()
        && ($user->isSuperAdmin() || $user->managedEvents()->where('events.id', $eventId)->exists());
});

/**
 * presenters.event.{id} — exact poll results for whoever is running the
 * show: the same staff as admin.event.{id} here, plus the event's speakers,
 * who have no account and are authorised by their stage link instead
 * (StageController::broadcastingAuth), never through this route.
 */
Broadcast::channel('presenters.event.{eventId}', function ($user, $eventId) {
    return $user->isStaff()
        && ($user->isSuperAdmin() || $user->managedEvents()->where('events.id', $eventId)->exists());
});
