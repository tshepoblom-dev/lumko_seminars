<?php

namespace App\Http\Controllers\Public;

use App\Enums\ReactionType;
use App\Events\ReactionAggregateUpdated;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Reaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReactionController extends Controller
{
    public function store(Request $request, Event $event): RedirectResponse|Response
    {
        $request->validate(['type' => ['required', Rule::in(ReactionType::current())]]);

        Reaction::create([
            'event_id' => $event->id,
            'programme_session_id' => $event->current_session_id,
            'user_id' => $request->user()->id,
            'type' => $request->input('type'),
        ]);

        // C1 — never one broadcast per click: at most one aggregate
        // broadcast per event per 2-second window. A click that loses the
        // race simply doesn't trigger a broadcast itself; the next click
        // within the window (or the one that acquires the lock after it
        // expires) carries the up-to-date counts anyway.
        $lock = Cache::add("reaction-broadcast:{$event->id}", true, now()->addSeconds(2));

        if ($lock) {
            broadcast(new ReactionAggregateUpdated($event->id, self::countsFor($event)));
        }

        // The live page sends reactions in the background (a tap shouldn't
        // reload the page); its emoji float up straight away on its own.
        return $request->wantsJson() ? response()->noContent() : back();
    }

    /** Current reaction counts for the event's session, as broadcast. */
    public static function countsFor(Event $event): array
    {
        return Reaction::where('event_id', $event->id)
            ->where('programme_session_id', $event->current_session_id)
            ->select('type', DB::raw('count(*) as aggregate'))
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->toArray();
    }
}
