<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use App\Notifications\PostEventThankYou;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D-Notifications — sends the post-event thank-you + feedback prompt
 * once per attendee, shortly after the event ends. Idempotent via the
 * sent_notifications ledger, same pattern as reminders:send.
 */
class SendPostEventCommand extends Command
{
    protected $signature = 'postevent:send';

    protected $description = 'Send the post-event thank-you/feedback notification once an event has ended';

    public function handle(): int
    {
        $sent = 0;

        // publiclyVisible(), not published(): staff mark an event completed
        // as soon as it ends, which must not skip the thank-you.
        foreach (Event::publiclyVisible()->get() as $event) {
            if (! $event->ends_at || $event->ends_at->isFuture()) {
                continue;
            }

            $recipients = User::whereHas('registrations', function ($query) use ($event) {
                $query->where('event_id', $event->id)->where('status', 'registered');
            })->get();

            foreach ($recipients as $recipient) {
                $claimed = DB::table('sent_notifications')->insertOrIgnore([
                    'user_id' => $recipient->id,
                    'event_id' => $event->id,
                    'type' => 'post_event_thank_you',
                    'sent_at' => now(),
                ]);

                if ($claimed) {
                    $recipient->notify(new PostEventThankYou($event));
                    $sent++;
                }
            }
        }

        $this->components->info("Sent {$sent} post-event notification(s).");

        return self::SUCCESS;
    }
}
