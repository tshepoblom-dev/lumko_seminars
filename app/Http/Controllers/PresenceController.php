<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\LivePresence;
use App\Services\AnalyticsService;
use App\Support\VisitorHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page heartbeat behind "live now" (Admin → Analytics) and the Live
 * page's online count. Every open tab pings on load, on each navigation
 * and every 30 seconds (resources/js/lib/presence.js).
 *
 * A heartbeat rather than Pusher presence channels: presence channels are
 * capped at 100 members on Pusher's default plan, and dev and production
 * share one Pusher app, so their members would mix.
 */
class PresenceController extends Controller
{
    public function ping(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $data = $request->validate([
            'route' => ['nullable', 'string', 'max:100'],
            'path' => ['nullable', 'string', 'max:255'],
            'event_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $route = $data['route'] ?? null;
        $eventId = $this->liveEventFor($request, $data['event_id'] ?? null, $route);

        // Staff and bots are never counted, but staff still see the number
        // (the admin "attendee view" of the Live page shows it too).
        if (! $user?->isStaff() && ! VisitorHash::isBot($request)) {
            LivePresence::updateOrCreate(
                ['session_hash' => $this->sessionHash($request)],
                [
                    'user_id' => $user?->id,
                    'event_id' => $user ? $eventId : null,
                    'route_name' => $route,
                    'path' => mb_substr((string) ($data['path'] ?? '/'), 0, 255),
                    'last_seen_at' => now(),
                ],
            );
        }

        return response()->json([
            'online' => $eventId ? $analytics->watching($eventId) : null,
        ]);
    }

    /** Tab closed: drop this session straight away rather than after the live window. */
    public function leave(Request $request): Response
    {
        LivePresence::where('session_hash', $this->sessionHash($request))->delete();

        return response()->noContent();
    }

    /**
     * The event whose Live page this is, only if the person may watch it:
     * an attendee registered for it, or staff (the admin preview).
     */
    private function liveEventFor(Request $request, ?int $eventId, ?string $route): ?int
    {
        $user = $request->user();

        if (! $eventId || ! $user || ! in_array($route, ['live.show', 'admin.live.preview'], true)) {
            return null;
        }

        if ($user->isStaff()) {
            return Event::whereKey($eventId)->exists() ? $eventId : null;
        }

        $registered = $route === 'live.show' && $user->registrations()
            ->where('event_id', $eventId)
            ->where('status', 'registered')
            ->exists();

        return $registered ? $eventId : null;
    }

    private function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }
}
