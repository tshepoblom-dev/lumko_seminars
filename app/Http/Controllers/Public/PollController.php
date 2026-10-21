<?php

namespace App\Http\Controllers\Public;

use App\Events\PollResultsUpdated;
use App\Events\PollUpdated;
use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PollController extends Controller
{
    /** Attendees get the new counts at most this often (Pusher deliveries). */
    private const AUDIENCE_BROADCAST_WINDOW_SECONDS = 2;

    public function respond(Poll $poll, PollOption $option): RedirectResponse
    {
        abort_unless($poll->status->value === 'open', 403, 'This poll is not open.');
        abort_unless($option->poll_id === $poll->id, 404);

        $response = $poll->responses()->firstOrCreate(['user_id' => Auth::id()], ['poll_option_id' => $option->id]);

        if ($response->wasRecentlyCreated) {
            // Presenters (a handful of subscribers) see every vote.
            broadcast(new PollResultsUpdated($poll));

            // Attendees only see counts when the poll shows results live,
            // and at most once per window, like reactions: a vote that
            // loses the race is carried by the next one, the voter's own
            // page reloads its counts, and closing sends the final ones.
            if ($poll->show_results_live
                && Cache::add("poll-broadcast:{$poll->id}", true, now()->addSeconds(self::AUDIENCE_BROADCAST_WINDOW_SECONDS))) {
                broadcast(new PollUpdated($poll, 'votes'));
            }
        }

        return back();
    }
}
