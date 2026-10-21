<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class QuestionPolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Question $question): bool
    {
        return $this->canAccessEvent($user, $question->event_id);
    }
}
