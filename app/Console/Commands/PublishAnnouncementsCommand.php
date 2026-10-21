<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use Illuminate\Console\Command;

/**
 * D-Announcements — respects a scheduled `published_at`: the moment it
 * arrives, this creates the in-app (dashboard/bell) notification for
 * every targeted, registered attendee. Email is deliberately NOT
 * triggered automatically here — it stays an explicit admin action
 * (AnnouncementMailer, via the "Send email" button), so scheduling a
 * publish time can never accidentally blast an inbox campaign. Marks
 * `send_started_at` so a slow/overlapping run doesn't double-notify.
 */
class PublishAnnouncementsCommand extends Command
{
    protected $signature = 'announcements:publish';

    protected $description = 'Create in-app notifications for announcements whose scheduled publish time has arrived';

    public function handle(): int
    {
        $published = 0;

        Announcement::whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereNull('send_started_at')
            ->get()
            ->each(function (Announcement $announcement) use (&$published) {
                $announcement->update(['send_started_at' => now()]);

                User::whereHas('registrations', function ($query) use ($announcement) {
                    $query->where('event_id', $announcement->event_id)->where('status', 'registered');

                    if ($announcement->audience !== 'all') {
                        $query->where('attendance_mode', $announcement->audience);
                    }
                })->chunkById(200, function ($recipients) use ($announcement) {
                    foreach ($recipients as $recipient) {
                        $recipient->notify(new AnnouncementPublished($announcement));
                    }
                });

                $published++;
            });

        $this->components->info("Published {$published} scheduled announcement(s) in-app.");

        return self::SUCCESS;
    }
}
