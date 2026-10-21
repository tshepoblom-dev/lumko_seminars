<?php

namespace App\Policies;

use App\Models\Partner;
use App\Models\User;

/**
 * Partners are one shared library, like speakers: any staff member manages
 * it, only super admins delete. Per-event involvement (tiers, publishing)
 * is authorised through the event (EventPolicy::update).
 *
 * The partner portal is for the partner's own team (partner_members);
 * super admins can open it too, to support them.
 */
class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Partner $partner): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Partner $partner): bool
    {
        return $user->isSuperAdmin();
    }

    public function accessPortal(User $user, Partner $partner): bool
    {
        return $user->isSuperAdmin() || $partner->hasMember($user);
    }

    /** Inviting and removing team members: the partner's owners (and super admins). */
    public function manageTeam(User $user, Partner $partner): bool
    {
        return $user->isSuperAdmin() || $partner->isOwner($user);
    }
}
