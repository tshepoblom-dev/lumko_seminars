<?php

namespace App\Policies;

use App\Models\Communication;
use App\Models\Event;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

/**
 * Event communications follow the event: super admins, and event managers
 * for their assigned events. Platform-wide communications (no event) reach
 * people across every event, so only super admins may see or send them.
 */
class CommunicationPolicy
{
    use ScopesToAssignedEvent;

    public function view(User $user, Communication $communication): bool
    {
        return $communication->isPlatformWide()
            ? $user->isSuperAdmin()
            : $this->canAccessEvent($user, $communication->event_id);
    }

    public function sendForEvent(User $user, Event $event): bool
    {
        return $this->canAccessEvent($user, $event->id);
    }

    public function sendPlatformWide(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
