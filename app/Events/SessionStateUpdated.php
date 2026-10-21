<?php

namespace App\Events;

use App\Models\Event as SeminarEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(public SeminarEvent $event) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("event.{$this->event->id}")];
    }

    public function broadcastAs(): string
    {
        return 'session.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'is_live' => $this->event->is_live,
            'player_open' => $this->event->player_open,
            'current_session' => $this->event->currentSession ? [
                'id' => $this->event->currentSession->id,
                'title' => $this->event->currentSession->title,
            ] : null,
        ];
    }
}
