<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class FeedbackPolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Feedback $feedback): bool
    {
        return $this->canAccessEvent($user, $feedback->event_id);
    }
}
