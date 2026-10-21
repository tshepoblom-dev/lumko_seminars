<?php

namespace App\Policies;

use App\Models\Resource;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class ResourcePolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Resource $resource): bool
    {
        return $this->canAccessEvent($user, $resource->event_id) || $resource->is_published;
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Resource $resource): bool
    {
        return $this->canAccessEvent($user, $resource->event_id);
    }

    public function delete(User $user, Resource $resource): bool
    {
        return $user->isSuperAdmin();
    }
}
