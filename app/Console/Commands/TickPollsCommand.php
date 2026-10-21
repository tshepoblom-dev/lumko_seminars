<?php

namespace App\Console\Commands;

use App\Enums\PollStatus;
use App\Models\Poll;
use App\Support\PollLifecycle;
use Illuminate\Console\Command;

/**
 * C6 — opens/closes polls on their scheduled opens_at/closes_at times.
 * Idempotent: only acts on polls still in the "wrong" state for the
 * current time, so running this twice in the same minute (or a missed
 * run catching up) never double-fires PollUpdated for the same poll.
 * Run every minute (see routes/console.php).
 */
class TickPollsCommand extends Command
{
    protected $signature = 'polls:tick';

    protected $description = 'Open/close polls whose scheduled opens_at/closes_at time has arrived';

    public function handle(): int
    {
        $opened = 0;
        $closed = 0;

        Poll::where('status', PollStatus::Draft->value)
            ->whereNotNull('opens_at')
            ->where('opens_at', '<=', now())
            ->get()
            ->each(function (Poll $poll) use (&$opened) {
                // C6 — single poll open per event: PollLifecycle closes
                // whatever else was open, same as the admin "open" action.
                PollLifecycle::open($poll, reason: 'scheduled');
                $opened++;
            });

        Poll::where('status', PollStatus::Open->value)
            ->whereNotNull('closes_at')
            ->where('closes_at', '<=', now())
            ->get()
            ->each(function (Poll $poll) use (&$closed) {
                PollLifecycle::close($poll, reason: 'scheduled');
                $closed++;
            });

        $this->components->info("Opened {$opened}, closed {$closed} scheduled poll(s).");

        return self::SUCCESS;
    }
}
