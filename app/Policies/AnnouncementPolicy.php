<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class AnnouncementPolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Announcement $announcement): bool
    {
        return $this->canAccessEvent($user, $announcement->event_id);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $this->canAccessEvent($user, $announcement->event_id);
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $user->isSuperAdmin();
    }
}
