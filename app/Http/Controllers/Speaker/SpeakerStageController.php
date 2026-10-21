<?php

namespace App\Http\Controllers\Speaker;

use App\Http\Controllers\Concerns\ServesSpeakerStage;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Speaker\Concerns\ResolvesSpeakerEvent;
use App\Models\Event;
use App\Models\Poll;
use App\Support\AgoraStage;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The speaker's stage and green room, signed in through the speaker
 * portal. The stage is the same page and rules as the no-login stage link
 * (ServesSpeakerStage); the green room is a separate Agora channel where
 * the speaker tests camera, mic and connection without attendees.
 */
class SpeakerStageController extends Controller
{
    use ResolvesSpeakerEvent, ServesSpeakerStage;

    public function show(Request $request, Event $event): SymfonyResponse
    {
        [$event, $speaker] = $this->resolveStage($request, $event);

        return $this->renderStage($event, $speaker, [
            'credentials' => route('speaker.events.stage.credentials', $event),
            'broadcastingAuth' => route('speaker.events.stage.broadcasting-auth', $event),
            'live' => route('speaker.events.stage.live', $event),
            'polls' => route('speaker.events.stage.polls.store', $event),
            'pollOpen' => route('speaker.events.stage.polls.open', [$event, '__POLL__']),
            'pollClose' => route('speaker.events.stage.polls.close', [$event, '__POLL__']),
        ], backUrl: route('speaker.dashboard'));
    }

    public function credentials(Request $request, Event $event): JsonResponse
    {
        [$event, $speaker] = $this->resolveStage($request, $event);

        return $this->stageCredentials($event, $speaker);
    }

    public function liveState(Request $request, Event $event): JsonResponse
    {
        [$event] = $this->resolveStage($request, $event);

        return $this->stageLiveState($event);
    }

    public function storePoll(Request $request, Event $event): JsonResponse
    {
        [$event, $speaker] = $this->resolveStage($request, $event);

        return $this->stageStorePoll($request, $event, $speaker);
    }

    public function openPoll(Request $request, Event $event, Poll $poll): JsonResponse
    {
        [$event, $speaker] = $this->resolveStage($request, $event);

        return $this->stageOpenPoll($event, $speaker, $poll);
    }

    public function closePoll(Request $request, Event $event, Poll $poll): JsonResponse
    {
        [$event, $speaker] = $this->resolveStage($request, $event);

        return $this->stageClosePoll($event, $speaker, $poll);
    }

    /**
     * /broadcasting/auth authorises staff and attendees by their channel
     * rules; a speaker's presenter channel is authorised by the stage, as
     * for the stage link.
     */
    public function broadcastingAuth(Request $request, Event $event): JsonResponse
    {
        [$event] = $this->resolveStage($request, $event);

        return $this->stageBroadcastingAuth($request, $event);
    }

    // --- Green room -------------------------------------------------------

    public function greenRoom(Request $request, Event $event): Response
    {
        $speaker = $this->speaker($request);
        $this->assertSpeaksAt($speaker, $event);

        $available = $event->livestream_provider?->value === 'agora'
            && $event->livestream_channel_name
            && ! $event->ends_at?->copy()->addHours(self::STAGE_GRACE_HOURS_AFTER_END)->isPast();

        return Inertia::render('Speaker/GreenRoom', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'provider' => $event->livestream_provider?->value,
            ],
            'available' => (bool) $available,
        ]);
    }

    /**
     * Two sets of credentials on the green-room channel: a publisher for
     * the speaker's camera, mic and screen, and a subscriber for the
     * loopback monitor that shows them what the audience would receive.
     */
    public function greenRoomCredentials(Request $request, Event $event): JsonResponse
    {
        [$event, $speaker] = $this->resolveStage($request, $event);
        $channel = AgoraStage::greenRoomChannel($event);

        $publisher = AgoraStage::publisherCredentials(
            $event,
            AgoraStage::speakerUid($speaker),
            AgoraStage::SPEAKER_TOKEN_TTL_MINUTES * 60,
            $channel,
        );

        Audit::record('speaker.greenroom_token_issued', subject: $speaker, eventId: $event->id);

        return $this->noIndex(response()->json([
            ...$publisher,
            'monitor' => AgoraStage::subscriberCredentials($event, $channel),
        ]));
    }

    /** @return array{0: Event, 1: \App\Models\Speaker} */
    private function resolveStage(Request $request, Event $event): array
    {
        $speaker = $this->speaker($request);
        $this->assertSpeaksAt($speaker, $event);
        $this->assertStageOpen($event);

        return [$event, $speaker];
    }
}
