<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use App\Notifications\EventReminder1h;
use App\Notifications\EventReminder24h;
use App\Notifications\EventStartingSoon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D-Notifications — three reminder types, each fired once per attendee
 * per event via the sent_notifications ledger (a unique constraint on
 * user_id+event_id+type makes a race between overlapping runs safe: the
 * loser's insert just fails and is skipped, not a duplicate email).
 * Runs every 5 minutes; each reminder's trigger condition ("within N of
 * starts_at") stays true across many ticks, but the ledger check means
 * only the first tick where it's true actually sends anything.
 */
class SendRemindersCommand extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Send the 24h/1h/starting-soon event reminders to registered attendees';

    public function handle(): int
    {
        $sent = 0;

        // published() already excludes archived (status is a single value).
        foreach (Event::published()->get() as $event) {
            if (! $event->starts_at) {
                continue;
            }

            if (now()->greaterThanOrEqualTo($event->starts_at->copy()->subHours(24)) && now()->lessThan($event->starts_at)) {
                $sent += $this->sendOnce($event, '24h', EventReminder24h::class);
            }

            if (now()->greaterThanOrEqualTo($event->starts_at->copy()->subHour()) && now()->lessThan($event->starts_at)) {
                $sent += $this->sendOnce($event, '1h', EventReminder1h::class);
            }

            if ($event->isWithinScheduledLeadWindow() && ! $event->is_live) {
                $sent += $this->sendOnce($event, 'starting_soon', EventStartingSoon::class);
            }
        }

        $this->components->info("Sent {$sent} reminder notification(s).");

        return self::SUCCESS;
    }

    private function sendOnce(Event $event, string $type, string $notificationClass): int
    {
        $count = 0;

        $recipients = User::whereHas('registrations', function ($query) use ($event) {
            $query->where('event_id', $event->id)->where('status', 'registered');
        })->get();

        foreach ($recipients as $recipient) {
            $claimed = DB::table('sent_notifications')->insertOrIgnore([
                'user_id' => $recipient->id,
                'event_id' => $event->id,
                'type' => $type,
                'sent_at' => now(),
            ]);

            if ($claimed) {
                $recipient->notify(new $notificationClass($event));
                $count++;
            }
        }

        return $count;
    }
}
