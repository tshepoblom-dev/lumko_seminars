<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * D-Notifications — the in-app (dashboard/bell) counterpart to
 * AnnouncementNotification (which is the *email*). Database-only,
 * created once per attendee when the announcement's published_at
 * arrives (announcements:publish); emailing it is always a separate,
 * explicit admin action (AnnouncementMailer), never automatic.
 */
class AnnouncementPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Announcement $announcement) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'body' => $this->announcement->body,
            'type' => $this->announcement->type->value,
        ];
    }
}
