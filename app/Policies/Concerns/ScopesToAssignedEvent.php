<?php

namespace App\Policies\Concerns;

use App\Models\Event;
use App\Models\User;

/**
 * B11 — the one place "is this staff member allowed to act on this
 * event's data" is decided: super admins always can; event managers only
 * for events they're assigned to (Event::managers() / event_user pivot).
 * Every policy below composes this instead of just checking isStaff().
 */
trait ScopesToAssignedEvent
{
    protected function canAccessEvent(User $user, ?int $eventId): bool
    {
        if (! $user->isStaff()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $eventId) {
            return false;
        }

        return Event::whereKey($eventId)
            ->whereHas('managers', fn ($q) => $q->where('users.id', $user->id))
            ->exists();
    }
}
