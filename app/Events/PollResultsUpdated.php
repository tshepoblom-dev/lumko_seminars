<?php

namespace App\Events;

use App\Models\Poll;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Exact poll results for the people running the show — staff and the
 * event's speakers on presenters.event.{id} — on every vote and every
 * open/close. Attendees get counts only through PollUpdated, and only when
 * the poll shows results live (B6/T-RT-2); this channel is never theirs.
 * A handful of subscribers, so one broadcast per vote is cheap.
 */
class PollResultsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(public Poll $poll) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("presenters.event.{$this->poll->event_id}")];
    }

    public function broadcastAs(): string
    {
        return 'poll.results';
    }

    public function broadcastWith(): array
    {
        return ['poll' => self::summary($this->poll)];
    }

    /** A poll as the presenter views show it, with exact counts. */
    public static function summary(Poll $poll): array
    {
        $poll->loadMissing('options');
        $counts = $poll->responses()
            ->selectRaw('poll_option_id, count(*) as aggregate')
            ->groupBy('poll_option_id')
            ->pluck('aggregate', 'poll_option_id');

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'status' => $poll->status->value,
            'show_results_live' => (bool) $poll->show_results_live,
            'total' => (int) $counts->sum(),
            'options' => $poll->options->map(fn ($o) => [
                'id' => $o->id,
                'label' => $o->label,
                'votes' => (int) ($counts[$o->id] ?? 0),
            ])->values()->all(),
        ];
    }
}
