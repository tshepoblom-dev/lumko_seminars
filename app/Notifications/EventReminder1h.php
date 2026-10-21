<?php

namespace App\Notifications;

use App\Models\Event;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventReminder1h extends Notification implements ShouldQueue
{
    use HasTransactionalFooter, Queueable;

    public function __construct(public Event $event) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Starting in an hour: {$this->event->name}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("\"{$this->event->name}\" starts in about an hour, at {$this->event->starts_at->format('H:i')}.")
            ->action('View your dashboard', route('attendee.dashboard'));

        return $this->withFooter($mail);
    }

    public function toArray($notifiable): array
    {
        return ['event_id' => $this->event->id, 'message' => "\"{$this->event->name}\" starts in about an hour."];
    }
}
