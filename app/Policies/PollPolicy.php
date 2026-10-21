<?php

namespace App\Policies;

use App\Models\Poll;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class PollPolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Poll $poll): bool
    {
        return $this->canAccessEvent($user, $poll->event_id);
    }
}
