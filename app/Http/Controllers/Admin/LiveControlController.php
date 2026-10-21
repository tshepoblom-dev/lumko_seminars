<?php

namespace App\Http\Controllers\Admin;

use App\Events\SessionStateUpdated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\LiveController;
use App\Models\Event;
use App\Models\ProgrammeSession;
use App\Services\AnalyticsService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LiveControlController extends Controller
{
    public function index(Event $event): Response
    {
        $this->authorize('update', $event);

        $event->load(['programmeSessions', 'currentSession']);

        return Inertia::render('Admin/Live/ControlRoom', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'is_live' => $event->is_live,
                'player_open' => $event->player_open,
                'player_opens_at' => $event->liveOpensAt()?->toIso8601String(),
                'current_session_id' => $event->current_session_id,
                'livestream_provider' => $event->livestream_provider?->value,
            ],
            'sessions' => $event->programmeSessions->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'status' => $s->status,
            ]),
        ]);
    }

    /** Attendees on this event's Live page right now (the control room polls this). */
    public function online(Event $event, AnalyticsService $analytics): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json(['online' => $analytics->watching($event->id)]);
    }

    /**
     * Staff "attendee view": the real /live page for this event, opened in
     * a new tab from the Control room / Broadcast console. Staff already
     * bypass the attendee gate and the Agora lead window, so anything sent
     * from here (questions, reactions, poll answers) is real.
     */
    public function preview(Event $event, LiveController $live): Response
    {
        $this->authorize('view', $event);

        return Inertia::render('Public/Live', $live->pageProps($event, preview: true));
    }

    /**
     * C6 — "Start session": marks it current and live, and closes out
     * whatever session was previously running.
     */
    public function startSession(Event $event, ProgrammeSession $programmeSession): RedirectResponse
    {
        $session = $programmeSession;
        $this->authorize('update', $event);
        abort_unless($session->event_id === $event->id, 404);

        $event->programmeSessions()->where('status', 'live')->where('id', '!=', $session->id)
            ->update(['status' => 'ended', 'ended_at' => now()]);

        $session->update(['status' => 'live', 'started_at' => now()]);
        $event->update(['current_session_id' => $session->id]);

        broadcast(new SessionStateUpdated($event->fresh('currentSession')));
        Audit::record('session.started', subject: $session, eventId: $event->id);

        return back()->with('success', "Started: {$session->title}");
    }

    public function endSession(Event $event, ProgrammeSession $programmeSession): RedirectResponse
    {
        $session = $programmeSession;
        $this->authorize('update', $event);
        abort_unless($session->event_id === $event->id, 404);

        $session->update(['status' => 'ended', 'ended_at' => now()]);

        broadcast(new SessionStateUpdated($event->fresh('currentSession')));
        Audit::record('session.ended', subject: $session, eventId: $event->id);

        return back()->with('success', "Ended: {$session->title}");
    }

    public function goLive(Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $event->update(['is_live' => true]);
        broadcast(new SessionStateUpdated($event->fresh('currentSession')));
        Audit::record('event.go_live', subject: $event, eventId: $event->id);

        return back()->with('success', 'Event is now live.');
    }

    public function endLive(Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        // Ending the event also closes an early-opened player.
        $event->update(['is_live' => false, 'player_open' => false]);
        broadcast(new SessionStateUpdated($event->fresh('currentSession')));
        Audit::record('event.end_live', subject: $event, eventId: $event->id);

        return back()->with('success', 'Event ended.');
    }

    /**
     * "Open the player now": registered attendees can join the Agora player
     * before the scheduled lead window — for rehearsals and tests. It
     * doesn't make the event live; nothing shows until a host broadcasts.
     */
    public function openPlayer(Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $event->update(['player_open' => true]);
        broadcast(new SessionStateUpdated($event->fresh('currentSession')));
        Audit::record('event.player_opened', subject: $event, eventId: $event->id);

        return back()->with('success', 'The player is open. Registered attendees can join now.');
    }

    public function closePlayer(Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $event->update(['player_open' => false]);
        broadcast(new SessionStateUpdated($event->fresh('currentSession')));
        Audit::record('event.player_closed', subject: $event, eventId: $event->id);

        return back()->with('success', 'The player is closed until the scheduled opening time.');
    }

    public function setCurrentSession(Event $event, ProgrammeSession $programmeSession): RedirectResponse
    {
        $session = $programmeSession;
        $this->authorize('update', $event);

        abort_unless($session->event_id === $event->id, 404);

        $event->update(['current_session_id' => $session->id]);
        broadcast(new SessionStateUpdated($event->fresh('currentSession')));
        Audit::record('event.session_changed', subject: $event, eventId: $event->id, after: ['session_id' => $session->id]);

        return back()->with('success', "Now showing: {$session->title}");
    }
}
