<?php

namespace App\Policies;

use App\Models\Connection;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

/**
 * B11/B9 — NetworkingController currently guards accept/decline with
 * inline `abort_unless` checks (only the recipient may respond); this
 * policy exists for staff-facing access (a future admin connections view)
 * and as the formal authorization surface those inline checks mirror.
 */
class ConnectionPolicy
{
    use ScopesToAssignedEvent;

    public function view(User $user, Connection $connection): bool
    {
        if ($user->isStaff()) {
            return $this->canAccessEvent($user, $connection->event_id);
        }

        return $user->id === $connection->requester_id || $user->id === $connection->recipient_id;
    }

    public function respond(User $user, Connection $connection): bool
    {
        return $user->id === $connection->recipient_id;
    }
}
