<?php

namespace App\Support;

use App\Enums\PollStatus;
use App\Events\PollResultsUpdated;
use App\Events\PollUpdated;
use App\Models\Poll;
use App\Models\Speaker;

/**
 * Opening and closing a poll, the same way wherever it happens: the admin
 * Polls page, the Broadcast console, a speaker's stage link, or the
 * scheduler (polls:tick).
 *
 * C6 — one open poll per event: opening a poll closes whatever else was
 * open, so /live only ever shows one.
 *
 * A speaker isn't a user account, so they're recorded in the audit
 * entry's details rather than as its actor.
 */
class PollLifecycle
{
    public static function open(Poll $poll, ?Speaker $speaker = null, ?string $reason = null): void
    {
        Poll::where('event_id', $poll->event_id)
            ->where('status', PollStatus::Open->value)
            ->where('id', '!=', $poll->id)
            ->get()
            ->each(fn (Poll $other) => self::close($other, $speaker, 'superseded_by_new_open_poll'));

        $poll->update(['status' => PollStatus::Open->value, 'opened_at' => now()]);
        broadcast(new PollUpdated($poll, 'opened'));
        broadcast(new PollResultsUpdated($poll));
        Audit::record('poll.opened', subject: $poll, eventId: $poll->event_id, after: self::details($speaker, $reason));
    }

    public static function close(Poll $poll, ?Speaker $speaker = null, ?string $reason = null): void
    {
        $poll->update(['status' => PollStatus::Closed->value, 'closed_at' => now()]);
        broadcast(new PollUpdated($poll, 'closed'));
        broadcast(new PollResultsUpdated($poll));
        Audit::record('poll.closed', subject: $poll, eventId: $poll->event_id, after: self::details($speaker, $reason));
    }

    private static function details(?Speaker $speaker, ?string $reason): ?array
    {
        $details = array_filter([
            'reason' => $reason,
            'speaker_id' => $speaker?->id,
            'speaker' => $speaker?->name,
        ], fn ($value) => $value !== null);

        return $details ?: null;
    }
}
