<?php

namespace App\Policies;

use App\Models\Speaker;
use App\Models\User;

class SpeakerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Speaker $speaker): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Speaker $speaker): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Speaker $speaker): bool
    {
        return $user->isSuperAdmin();
    }
}
