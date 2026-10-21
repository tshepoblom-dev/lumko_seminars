<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Event;
use App\Models\Poll;
use App\Support\AgoraStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LiveController extends Controller
{
    public function show(Request $request): Response
    {
        // Set by EnsureEventAttendee — avoids re-querying "the current
        // event" a second time and keeps this in sync with whatever event
        // the attendee gate actually resolved (staff previewing get it
        // too; see the middleware).
        $event = $request->attributes->get('currentEvent');
        abort_unless($event instanceof Event, 404);

        return Inertia::render('Public/Live', $this->pageProps($event));
    }

    /**
     * The /live page's props for one event — shared with the admin "attendee
     * view" preview (Admin\LiveControlController::preview), which renders
     * this same page for the event being managed rather than whichever
     * event is currently public.
     */
    public function pageProps(Event $event, bool $preview = false): array
    {
        $openPoll = Poll::where('event_id', $event->id)->where('status', 'open')
            ->with(['options' => fn ($q) => $q->withCount('responses')])
            ->first();

        return [
            'preview' => $preview,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'is_live' => $event->is_live,
                'player_open' => $event->player_open,
                'has_ended' => $event->ends_at?->isPast() ?? false,
                // Before this the Agora player shows when it opens, rather
                // than a Join button whose token request would be refused.
                // Staff (and so the preview) can always join.
                'within_live_window' => $event->isWithinLiveLeadWindow() || (bool) Auth::user()?->isStaff(),
                'opens_at' => $event->liveOpensAt()?->toIso8601String(),
                'presenters' => $event->livestream_provider?->value === 'agora' ? AgoraStage::presenterNames($event) : [],
                'livestream_provider' => $event->livestream_provider?->value,
                'livestream_url' => $event->livestream_url,
                'livestream_channel_name' => $event->livestream_channel_name,
                'livestream_secondary_provider' => $event->livestream_secondary_provider?->value,
                'livestream_secondary_url' => $event->livestream_secondary_url,
                'recording_url' => $event->recording_url,
                'current_session' => $event->currentSession ? [
                    'id' => $event->currentSession->id,
                    'title' => $event->currentSession->title,
                ] : null,
            ],
            'questions' => $event->questions()->approved()->withCount('votes')
                ->orderByDesc('is_featured')->orderByDesc('votes_count')->get()
                ->map(fn ($q) => [
                    'id' => $q->id,
                    'body' => $q->body,
                    'is_featured' => $q->is_featured,
                    'status' => $q->status->value,
                    'votes_count' => $q->votes_count,
                    'voted_by_me' => $q->votes()->where('user_id', Auth::id())->exists(),
                ]),
            // D-Registration/C2 — the attendee's own pending questions,
            // shown separately from the approved public list above.
            'myPendingQuestions' => $event->questions()
                ->where('user_id', Auth::id())
                ->whereIn('status', ['submitted', 'hidden'])
                ->latest()->get(['id', 'body', 'status', 'created_at']),
            'poll' => $openPoll ? $this->formatPoll($openPoll) : null,
            'chat' => ChatMessage::recentFor($event),
            // The baseline the page compares broadcast counts against, so only
            // new reactions float up.
            'reactionCounts' => (object) ReactionController::countsFor($event),
            // Staff (the admin "attendee view") can hide chat posts in place.
            'canModerate' => (bool) Auth::user()?->isStaff(),
        ];
    }

    /**
     * B6/T-RT-2 — vote counts are only ever sent when the admin has
     * switched results on for this poll (or it's closed); the payload
     * omits them entirely otherwise, matching PollUpdated's broadcast
     * gating so a network inspector can't see what the UI hides.
     */
    private function formatPoll(Poll $poll): array
    {
        $showResults = $poll->show_results_live || $poll->status->value === 'closed';

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'status' => $poll->status->value,
            'show_results_live' => $poll->show_results_live,
            'options' => $poll->options->map(fn ($o) => [
                'id' => $o->id,
                'label' => $o->label,
                'votes' => $showResults ? $o->responses_count : null,
            ]),
            'responded_by_me' => $poll->responses()->where('user_id', Auth::id())->exists(),
        ];
    }
}
