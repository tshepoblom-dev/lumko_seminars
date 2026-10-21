<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * B13 — super-admin only (AuditLogPolicy). Read-only, paginated,
 * filterable by action and actor. Never surfaces `before`/`after` blobs
 * that might contain a PoP's contents — Audit::record() is already
 * disciplined about never putting file contents in those columns, so
 * this page can safely show them as-is.
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $logs = AuditLog::with('actor')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$request->input('action').'%'))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('actor_id', $request->input('actor_id')))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Audit/Index', [
            'logs' => $logs->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->name ?? ($log->actor_role ? "({$log->actor_role})" : 'System'),
                'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                'subject_id' => $log->subject_id,
                'before' => $log->before,
                'after' => $log->after,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->format('d M Y H:i:s'),
            ]),
            'filters' => $request->only(['action', 'actor_id']),
        ]);
    }
}
