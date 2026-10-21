<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AnnouncementNotification extends Notification implements ShouldQueue
{
    use HasTransactionalFooter, Queueable;

    public function __construct(public Announcement $announcement) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->announcement->title)
            ->greeting("Hi {$notifiable->first_name},")
            ->line($this->announcement->body)
            ->line("— {$this->announcement->event->name}");

        return $this->withFooter($mail);
    }
}
