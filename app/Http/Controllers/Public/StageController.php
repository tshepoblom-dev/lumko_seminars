<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Concerns\ServesSpeakerStage;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Poll;
use App\Models\Speaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The remote speaker's stage: reached by the link staff create on the
 * Broadcast console, with no login. The link is the credential, so an
 * unknown, revoked or expired one is a plain 404 — it never confirms that
 * an event or speaker exists. Speakers with a portal account reach the
 * same stage signed in (Speaker\StageController).
 */
class StageController extends Controller
{
    use ServesSpeakerStage;

    public function show(string $token): Response
    {
        [$event, $speaker] = $this->resolve($token);

        return $this->renderStage($event, $speaker, [
            'credentials' => route('stage.credentials', $token),
            'broadcastingAuth' => route('stage.broadcasting-auth', $token),
            'live' => route('stage.live', $token),
            'polls' => route('stage.polls.store', $token),
            'pollOpen' => route('stage.polls.open', [$token, '__POLL__']),
            'pollClose' => route('stage.polls.close', [$token, '__POLL__']),
        ]);
    }

    public function credentials(string $token): JsonResponse
    {
        [$event, $speaker] = $this->resolve($token);

        return $this->stageCredentials($event, $speaker);
    }

    // --- The speaker's presenter panel: polls, questions, chat -------------

    public function liveState(string $token): JsonResponse
    {
        [$event] = $this->resolve($token);

        return $this->stageLiveState($event);
    }

    public function storePoll(Request $request, string $token): JsonResponse
    {
        [$event, $speaker] = $this->resolve($token);

        return $this->stageStorePoll($request, $event, $speaker);
    }

    public function openPoll(string $token, Poll $poll): JsonResponse
    {
        [$event, $speaker] = $this->resolve($token);

        return $this->stageOpenPoll($event, $speaker, $poll);
    }

    public function closePoll(string $token, Poll $poll): JsonResponse
    {
        [$event, $speaker] = $this->resolve($token);

        return $this->stageClosePoll($event, $speaker, $poll);
    }

    /** Speakers on a link have no account for /broadcasting/auth; the link stands in. */
    public function broadcastingAuth(Request $request, string $token): JsonResponse
    {
        [$event] = $this->resolve($token);

        return $this->stageBroadcastingAuth($request, $event);
    }

    /** @return array{0: Event, 1: Speaker} */
    private function resolve(string $token): array
    {
        $link = DB::table('event_speaker')
            ->where('stage_token_hash', hash('sha256', $token))
            ->first(['event_id', 'speaker_id']);

        abort_unless($link, 404);

        $event = Event::find($link->event_id);
        $speaker = Speaker::find($link->speaker_id);

        abort_unless($event && $speaker, 404);
        $this->assertStageOpen($event);

        return [$event, $speaker];
    }
}
