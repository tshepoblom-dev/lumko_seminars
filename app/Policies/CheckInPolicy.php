<?php

namespace App\Policies;

use App\Models\CheckIn;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class CheckInPolicy
{
    use ScopesToAssignedEvent;

    /**
     * Any staff member may operate the check-in scanner; the event itself
     * (not per-check-in access) is what CheckInController/CheckInService
     * resolve and enforce.
     */
    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, CheckIn $checkIn): bool
    {
        return $this->canAccessEvent($user, $checkIn->ticket?->registration?->event_id);
    }
}
