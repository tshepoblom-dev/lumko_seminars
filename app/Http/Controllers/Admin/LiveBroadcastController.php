<?php

namespace App\Http\Controllers\Admin;

use App\Events\SessionStateUpdated;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Speaker;
use App\Support\AgoraStage;
use App\Support\Audit;
use App\Support\LiveState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * C5 — the Agora host/publisher console. Only staff assigned to the
 * event (or a super admin) may operate it (EventPolicy::broadcast).
 * It also hands out stage links, which let remote speakers present on
 * the same channel without an account (Public\StageController).
 */
class LiveBroadcastController extends Controller
{
    public function show(Event $event): Response
    {
        $this->authorize('broadcast', $event);

        // Broadcast is in every event's workspace sidebar, so an event that
        // doesn't stream through Agora gets an explanation, not a 404.
        if ($event->livestream_provider?->value !== 'agora' || ! $event->livestream_channel_name) {
            return Inertia::render('Admin/Live/BroadcastUnavailable', [
                'reason' => $event->livestream_provider?->value !== 'agora' ? 'not_agora' : 'no_channel',
                'provider' => $event->livestream_provider?->value,
            ]);
        }

        return Inertia::render('Admin/Live/Broadcast', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'is_live' => $event->is_live,
                'channel_name' => $event->livestream_channel_name,
            ],
            'speakers' => $event->speakers()
                ->withPivot(['stage_token_hash', 'stage_token_created_at'])
                ->get()
                ->map(fn (Speaker $speaker) => [
                    'id' => $speaker->id,
                    'name' => $speaker->name,
                    'uid' => AgoraStage::speakerUid($speaker),
                    'has_stage_link' => $speaker->pivot->stage_token_hash !== null,
                    'stage_link_created_at' => $speaker->pivot->stage_token_created_at,
                ]),
        ]);
    }

    /** The console's presenter panel: polls, the Q&A queue and chat (JSON). */
    public function liveState(Event $event): JsonResponse
    {
        $this->authorize('broadcast', $event);

        return response()->json(LiveState::forPresenters($event, staff: true));
    }

    public function token(Request $request, Event $event): JsonResponse
    {
        $this->authorize('broadcast', $event);

        // The green room: a sound check with speakers on a separate channel
        // that attendees are never given a token for.
        $greenRoom = $request->boolean('greenroom');

        // C5 — publisher role, uid < 100000 (reserved for staff hosts,
        // distinct from the speaker and audience ranges in AgoraStage).
        $credentials = AgoraStage::publisherCredentials(
            $event,
            AgoraStage::staffUid($request->user()),
            channel: $greenRoom ? AgoraStage::greenRoomChannel($event) : null,
        );

        Audit::record($greenRoom ? 'event.greenroom_token_issued' : 'event.broadcast_token_issued', subject: $event, eventId: $event->id, actor: $request->user());

        return response()->json($credentials);
    }

    public function start(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('broadcast', $event);

        $event->update(['is_live' => true]);
        broadcast(new SessionStateUpdated($event->fresh('currentSession')));
        Audit::record('event.broadcast_started', subject: $event, eventId: $event->id, actor: $request->user());

        return back()->with('success', 'Broadcast started — you are now live.');
    }

    public function stop(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('broadcast', $event);

        $event->update(['is_live' => false]);
        broadcast(new SessionStateUpdated($event->fresh('currentSession')));
        Audit::record('event.broadcast_stopped', subject: $event, eventId: $event->id, actor: $request->user());

        return back()->with('success', 'Broadcast stopped.');
    }

    /**
     * Creates (or replaces) a speaker's stage link. The raw token is
     * returned once, here, and only its hash is kept — so a lost link is
     * regenerated, never looked up.
     */
    public function createStageLink(Request $request, Event $event, Speaker $speaker): JsonResponse
    {
        $this->authorize('broadcast', $event);
        abort_unless($event->speakers()->whereKey($speaker->id)->exists(), 404);

        $token = Str::random(48);

        $event->speakers()->updateExistingPivot($speaker->id, [
            'stage_token_hash' => hash('sha256', $token),
            'stage_token_created_at' => now(),
        ]);

        Audit::record('speaker.stage_link_created', subject: $speaker, eventId: $event->id, actor: $request->user());

        return response()->json(['url' => route('stage.show', $token)]);
    }

    public function revokeStageLink(Request $request, Event $event, Speaker $speaker): RedirectResponse
    {
        $this->authorize('broadcast', $event);
        abort_unless($event->speakers()->whereKey($speaker->id)->exists(), 404);

        $event->speakers()->updateExistingPivot($speaker->id, [
            'stage_token_hash' => null,
            'stage_token_created_at' => null,
        ]);

        Audit::record('speaker.stage_link_revoked', subject: $speaker, eventId: $event->id, actor: $request->user());

        return back()->with('success', "{$speaker->name}'s stage link no longer works. If they're on stage now, they drop off within ".AgoraStage::SPEAKER_TOKEN_TTL_MINUTES.' minutes.');
    }
}
