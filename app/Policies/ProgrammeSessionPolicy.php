<?php

namespace App\Policies;

use App\Models\ProgrammeSession;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class ProgrammeSessionPolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, ProgrammeSession $programmeSession): bool
    {
        return $this->canAccessEvent($user, $programmeSession->event_id);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, ProgrammeSession $programmeSession): bool
    {
        return $this->canAccessEvent($user, $programmeSession->event_id);
    }

    public function delete(User $user, ProgrammeSession $programmeSession): bool
    {
        return $user->isSuperAdmin();
    }
}
