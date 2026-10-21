<?php

namespace App\Support;

use App\Http\Controllers\Admin\PollController;
use App\Http\Controllers\Admin\QuestionController;
use App\Models\ChatMessage;
use App\Models\Event;

/**
 * What the presenter panel shows (Broadcast console and speaker stage):
 * polls with exact counts, questions, and the chat. Loaded once, then kept
 * live over the event and presenters channels (or polled if those fail).
 *
 * Staff get the whole moderation queue, pending questions included, so
 * they can approve from the console. Speakers see only approved questions,
 * as attendees do.
 */
class LiveState
{
    public static function forPresenters(Event $event, bool $staff): array
    {
        return [
            'polls' => PollController::summaries($event),
            'questions' => $staff
                ? QuestionController::moderationList($event)
                : self::approvedQuestions($event),
            'chat' => ChatMessage::recentFor($event),
        ];
    }

    public static function approvedQuestions(Event $event): array
    {
        return $event->questions()->approved()->withCount('votes')
            ->orderByDesc('is_featured')->orderByDesc('votes_count')->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'body' => $q->body,
                'status' => $q->status->value,
                'is_featured' => $q->is_featured,
                'votes_count' => $q->votes_count,
            ])
            ->all();
    }
}
