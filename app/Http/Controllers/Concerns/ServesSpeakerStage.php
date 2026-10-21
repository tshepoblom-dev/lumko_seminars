<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\Admin\PollController;
use App\Models\Event;
use App\Models\Poll;
use App\Models\Speaker;
use App\Support\AgoraStage;
use App\Support\Audit;
use App\Support\LiveState;
use App\Support\PollLifecycle;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The speaker's stage, whichever way the speaker got in: the no-login stage
 * link (Public\StageController) or the speaker portal (Speaker\
 * StageController). The using controller resolves (event, speaker) and
 * hands them to these; it also builds the URLs the page calls back on.
 */
trait ServesSpeakerStage
{
    /** How long after the event ends the stage keeps working. */
    protected const STAGE_GRACE_HOURS_AFTER_END = 2;

    /** The rules for a stage being open at all, shared by both ways in. */
    protected function assertStageOpen(Event $event): void
    {
        abort_unless(in_array($event->status, ['published', 'completed'], true), 404);
        abort_unless($event->livestream_provider?->value === 'agora' && $event->livestream_channel_name, 404);
        abort_if($event->ends_at?->copy()->addHours(self::STAGE_GRACE_HOURS_AFTER_END)->isPast(), 404);
    }

    protected function renderStage(Event $event, Speaker $speaker, array $endpoints, ?string $backUrl = null): Response
    {
        $response = Inertia::render('Public/Stage', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'is_live' => $event->is_live,
            ],
            'speaker' => ['name' => $speaker->name],
            'endpoints' => $endpoints,
            'backUrl' => $backUrl,
        ])->toResponse(request());

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    protected function stageCredentials(Event $event, Speaker $speaker): JsonResponse
    {
        $credentials = AgoraStage::publisherCredentials(
            $event,
            AgoraStage::speakerUid($speaker),
            AgoraStage::SPEAKER_TOKEN_TTL_MINUTES * 60
        );

        Audit::record('speaker.stage_token_issued', subject: $speaker, eventId: $event->id);

        return $this->noIndex(response()->json($credentials));
    }

    /** Polls (exact counts), approved questions and chat, as JSON. */
    protected function stageLiveState(Event $event): JsonResponse
    {
        return $this->noIndex(response()->json(LiveState::forPresenters($event, staff: false)));
    }

    protected function stageStorePoll(Request $request, Event $event, Speaker $speaker): JsonResponse
    {
        $poll = PollController::createPoll($event, PollController::validatePoll($request), createdBySpeaker: $speaker->id);
        Audit::record('poll.created', subject: $poll, eventId: $event->id, after: ['speaker_id' => $speaker->id, 'speaker' => $speaker->name]);

        return $this->noIndex(response()->json(['polls' => PollController::summaries($event)], 201));
    }

    protected function stageOpenPoll(Event $event, Speaker $speaker, Poll $poll): JsonResponse
    {
        abort_unless($poll->event_id === $event->id, 404);

        PollLifecycle::open($poll, $speaker);

        return $this->noIndex(response()->json(['polls' => PollController::summaries($event)]));
    }

    protected function stageClosePoll(Event $event, Speaker $speaker, Poll $poll): JsonResponse
    {
        abort_unless($poll->event_id === $event->id, 404);

        PollLifecycle::close($poll, $speaker);

        return $this->noIndex(response()->json(['polls' => PollController::summaries($event)]));
    }

    /**
     * Pusher channel authorisation for the speaker: only this event's
     * audience and presenter channels, never the staff moderation channel
     * (admin.event.{id}).
     */
    protected function stageBroadcastingAuth(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string'],
        ]);

        $allowed = ["private-event.{$event->id}", "private-presenters.event.{$event->id}"];
        abort_unless(in_array($data['channel_name'], $allowed, true), 403);

        $broadcaster = Broadcast::driver();

        // The log/null drivers (tests, local dev without Pusher) have
        // nothing to sign.
        $auth = $broadcaster instanceof PusherBroadcaster
            ? json_decode($broadcaster->getPusher()->authorizeChannel($data['channel_name'], $data['socket_id']), true)
            : [];

        return $this->noIndex(response()->json($auth));
    }

    protected function noIndex(JsonResponse $response): JsonResponse
    {
        return $response->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
