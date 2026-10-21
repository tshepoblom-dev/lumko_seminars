<?php

namespace App\Events;

use App\Models\Question;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBroadcastNow like every other live event: a queued broadcast only
 * reached the moderators if a queue worker happened to be running, so new
 * questions never showed up live on the moderation page.
 */
class QuestionSubmittedForModeration implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(public Question $question) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("admin.event.{$this->question->event_id}")];
    }

    public function broadcastAs(): string
    {
        return 'question.pending';
    }

    public function broadcastWith(): array
    {
        // Same shape as a row on the moderation page, so it can be added as-is.
        return [
            'id' => $this->question->id,
            'body' => $this->question->body,
            'user_name' => $this->question->user->name,
            'status' => $this->question->status->value,
            'is_featured' => (bool) $this->question->is_featured,
            'votes_count' => 0,
        ];
    }
}
