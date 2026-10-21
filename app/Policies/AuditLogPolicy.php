<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * B13 — the audit log is super-admin only. Event managers, however
 * trusted, do not get to read the full cross-event action history.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->isSuperAdmin();
    }
}
