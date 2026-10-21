<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A new live-chat post, to everyone on the event's channel at once. */
class ChatMessagePosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(public ChatMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("event.{$this->message->event_id}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.posted';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message->toPublicArray()];
    }
}
