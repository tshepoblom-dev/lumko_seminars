<?php

namespace App\Http\Middleware;

use App\Models\Event;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every admin page under /admin/events/{event} renders inside the event
 * workspace (Layouts/EventWorkspaceLayout.vue): a header naming the event,
 * an event switcher and the event's own sidebar. This shares what that
 * layout needs once, instead of every controller adding it to its props.
 */
class ShareEventWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $event = $request->route('event');

        if ($event instanceof Event && $request->user()?->can('view', $event)) {
            Inertia::share('workspace', fn () => [
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'status' => $event->status,
                    'is_live' => (bool) $event->is_live,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                ],
                'events' => Event::visibleTo($request->user())
                    ->orderByDesc('starts_at')
                    ->get(['id', 'name', 'status', 'starts_at'])
                    ->map(fn (Event $e) => [
                        'id' => $e->id,
                        'name' => $e->name,
                        'status' => $e->status,
                        'starts_at' => $e->starts_at?->toIso8601String(),
                    ]),
            ]);
        }

        return $next($request);
    }
}
