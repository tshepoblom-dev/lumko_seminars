<?php

namespace App\Events;

use App\Models\Question;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * C1 — ShouldBroadcastNow: dispatched synchronously, not queued, so a
 * moderation action shows up on /live within the request/response cycle
 * rather than waiting on a queue worker (fine for ~30 concurrent users).
 */
class QuestionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(public Question $question, public string $action) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("event.{$this->question->event_id}")];
    }

    public function broadcastAs(): string
    {
        return 'question.updated';
    }

    /**
     * B6/T-RT-1 — a hidden/archived question broadcasts only its id,
     * action and status, never the body: the moderation queue and the
     * public /live page share this one event, and the body must not
     * leak to attendees for a question staff just hid.
     */
    public function broadcastWith(): array
    {
        $publicStatuses = ['approved', 'answered'];

        if (! in_array($this->question->status->value, $publicStatuses, true)) {
            return [
                'action' => $this->action,
                'question' => [
                    'id' => $this->question->id,
                    'status' => $this->question->status->value,
                ],
            ];
        }

        return [
            'action' => $this->action,
            'question' => [
                'id' => $this->question->id,
                'body' => $this->question->body,
                'is_featured' => $this->question->is_featured,
                'status' => $this->question->status->value,
                'votes_count' => $this->question->votes()->count(),
            ],
        ];
    }
}
