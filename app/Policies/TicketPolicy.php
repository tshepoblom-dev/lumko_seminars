<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class TicketPolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $this->canAccessEvent($user, $ticket->registration?->event_id);
    }
}
