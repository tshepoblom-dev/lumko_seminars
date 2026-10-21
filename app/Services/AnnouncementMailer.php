<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;

/**
 * B14/D-Announcements — the one place an announcement's email actually
 * goes out, shared by the admin "send email" action and the
 * announcements:publish scheduler tick, so the idempotency lock,
 * audience targeting and audit trail can't drift between the two.
 */
class AnnouncementMailer
{
    /**
     * @return int|null the recipient count, or null if a send was already
     *                  in progress / already sent and $resend is false.
     */
    public function send(Announcement $announcement, bool $resend = false): ?int
    {
        if ($announcement->emailed_at && ! $resend) {
            return null;
        }

        $lock = Cache::lock("announcement-send:{$announcement->id}", 30);

        if (! $lock->get()) {
            return null;
        }

        try {
            $wasResend = (bool) $announcement->emailed_at;
            $count = 0;

            User::whereHas('registrations', function ($query) use ($announcement) {
                $query->where('event_id', $announcement->event_id)->where('status', 'registered');

                if ($announcement->audience !== 'all') {
                    $query->where('attendance_mode', $announcement->audience);
                }
            })->chunkById(200, function ($recipients) use ($announcement, &$count) {
                foreach ($recipients as $recipient) {
                    $recipient->notify(new AnnouncementNotification($announcement));
                    $count++;
                }
            });

            $announcement->update(['emailed_at' => now()]);

            Audit::record(
                $wasResend ? 'announcement.resent' : 'announcement.sent',
                subject: $announcement, eventId: $announcement->event_id,
                after: ['recipient_count' => $count],
            );

            return $count;
        } finally {
            $lock->release();
        }
    }
}
