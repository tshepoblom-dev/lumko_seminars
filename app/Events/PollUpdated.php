<?php

namespace App\Events;

use App\Models\Poll;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PollUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(public Poll $poll, public string $action) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("event.{$this->poll->event_id}")];
    }

    public function broadcastAs(): string
    {
        return 'poll.updated';
    }

    /**
     * B6/T-RT-2 — per-option vote counts are included only when the admin
     * has switched results on for this poll (show_results_live) or it's
     * closed. Otherwise they're omitted server-side entirely, not just
     * hidden in the UI — the payload itself carries nothing to leak.
     */
    public function broadcastWith(): array
    {
        $showResults = $this->poll->show_results_live || $this->poll->status->value === 'closed';

        return [
            'action' => $this->action,
            'poll' => [
                'id' => $this->poll->id,
                'question' => $this->poll->question,
                'status' => $this->poll->status->value,
                'show_results_live' => $this->poll->show_results_live,
                'options' => $this->poll->options->map(fn ($o) => array_filter([
                    'id' => $o->id,
                    'label' => $o->label,
                    'votes' => $showResults ? $o->responses()->count() : null,
                ], fn ($value, $key) => $key !== 'votes' || $value !== null, ARRAY_FILTER_USE_BOTH)),
            ],
        ];
    }
}
