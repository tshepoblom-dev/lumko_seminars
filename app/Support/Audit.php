<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * B13 — audit_logs is insert-only (no update/delete anywhere touches it).
 * Never pass file contents (e.g. a proof-of-payment) in $before/$after —
 * log that it was viewed, not what it contains.
 */
class Audit
{
    public static function record(
        string $action,
        ?Model $subject = null,
        ?Model $actor = null,
        ?array $before = null,
        ?array $after = null,
        ?int $eventId = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role?->value,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'event_id' => $eventId,
            'before' => $before,
            'after' => $after,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::header('User-Agent'), 0, 500),
        ]);
    }
}
