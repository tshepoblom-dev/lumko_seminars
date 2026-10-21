<?php

namespace App\Http\Middleware;

use App\Models\Event;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'isStaff' => $request->user()->isStaff(),
                    'isSuperAdmin' => $request->user()->isSuperAdmin(),
                ] : null,
                // The portal switcher: every area this account can open.
                // Lazy, so pages that don't render a switcher skip the queries.
                'portals' => fn () => $request->user() ? [
                    'admin' => $request->user()->isStaff(),
                    'speaker' => $request->user()->isSpeaker(),
                    'partners' => $request->user()->partners()->get(['partners.id', 'partners.name', 'partners.slug'])
                        ->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name])->values(),
                ] : null,
            ],
            // D-Admin nav — every event-scoped admin page (Attendees,
            // Broadcast, Engagement) needs an {event} route param; this
            // resolves the one nav links should point at by default
            // (most recent non-archived event this staff member can
            // access) without every admin page having to compute it.
            'adminDefaultEventId' => fn () => $request->user() && $request->user()->isStaff()
                ? Event::visibleTo($request->user())->notArchived()->orderByDesc('starts_at')->value('id')
                : null,
            'seminar' => [
                'organiserName' => config('seminar.organiser_legal_name'),
                'privacyNoticeUrl' => config('seminar.privacy_notice_url'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'info' => fn () => $request->session()->get('info'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // D-Notifications — the AttendeeLayout bell. Lazy (only
            // evaluated when actually requested, including on Inertia
            // partial reloads) so pages that don't render the bell don't
            // pay the query cost.
            'notifications' => fn () => $request->user() ? [
                'unreadCount' => $request->user()->unreadNotifications()->count(),
                'recent' => $request->user()->notifications()->latest()->limit(10)->get()
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'message' => $n->data['message'] ?? $n->type,
                        'read' => $n->read_at !== null,
                        'created_at' => $n->created_at->diffForHumans(),
                    ]),
            ] : null,
        ];
    }
}
