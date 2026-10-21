<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class EventPolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Event $event): bool
    {
        return $this->canAccessEvent($user, $event->id);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Event $event): bool
    {
        return $this->canAccessEvent($user, $event->id);
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * B1/C5 — only staff assigned to the event (or a super admin) may
     * operate the Agora host console for it.
     */
    public function broadcast(User $user, Event $event): bool
    {
        return $this->canAccessEvent($user, $event->id);
    }
}
