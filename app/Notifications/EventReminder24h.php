<?php

namespace App\Notifications;

use App\Models\Event;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventReminder24h extends Notification implements ShouldQueue
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
            ->subject("Tomorrow: {$this->event->name}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("\"{$this->event->name}\" is happening tomorrow, {$this->event->starts_at->format('d F Y, H:i')}.")
            ->action('View your dashboard', route('attendee.dashboard'));

        return $this->withFooter($mail);
    }

    public function toArray($notifiable): array
    {
        return ['event_id' => $this->event->id, 'message' => "\"{$this->event->name}\" is tomorrow."];
    }
}
