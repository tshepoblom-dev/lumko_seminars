<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Audit;

/**
 * B11 — `role` is deliberately absent from User::$fillable so
 * `User::create([...,'role'=>'super_admin'])` can never grant admin
 * access via mass assignment. This service is the only place that sets
 * it, via forceFill(), and every change is audited.
 */
class RoleService
{
    public function assign(User $user, UserRole $role, ?User $actor = null): User
    {
        $before = $user->role?->value;

        $user->forceFill(['role' => $role])->save();

        Audit::record(
            action: 'user.role_changed',
            subject: $user,
            actor: $actor,
            before: ['role' => $before],
            after: ['role' => $role->value],
        );

        return $user;
    }
}
