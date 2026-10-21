<?php

namespace App\Policies;

use App\Models\EventRegistration;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

/**
 * B11 — governs admin access to an attendee's registration record (the
 * Stage D attendee-management pages). Not used for the attendee's own
 * access to their own registration, which never goes through a policy.
 *
 * Named EventRegistrationPolicy (not RegistrationPolicy) deliberately —
 * Laravel's policy auto-discovery derives the policy class name from the
 * model's basename (EventRegistration -> EventRegistrationPolicy). A
 * mismatched name here silently resolves to "no policy found" rather
 * than throwing, which is exactly how this was wrong the first time.
 */
class EventRegistrationPolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, EventRegistration $registration): bool
    {
        return $this->canAccessEvent($user, $registration->event_id);
    }

    public function update(User $user, EventRegistration $registration): bool
    {
        return $this->canAccessEvent($user, $registration->event_id);
    }
}
