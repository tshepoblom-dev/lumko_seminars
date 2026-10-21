<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * C1 — carries an *aggregate* count per reaction type, never one event per
 * click (a ~30-person room clicking reactions would otherwise burn through
 * a Pusher plan's message quota fast). See ReactionController for the
 * cache-lock throttle that limits this to roughly one broadcast per 2s.
 */
class ReactionAggregateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    /** @param array<string, int> $counts type => count, for the current session */
    public function __construct(public int $eventId, public array $counts) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("event.{$this->eventId}")];
    }

    public function broadcastAs(): string
    {
        return 'reaction.aggregate';
    }

    public function broadcastWith(): array
    {
        return ['counts' => $this->counts];
    }
}
