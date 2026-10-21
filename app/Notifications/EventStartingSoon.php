<?php

namespace App\Notifications;

use App\Models\Event;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent at the live-lead-window boundary — "join now" rather than "it's soon." */
class EventStartingSoon extends Notification implements ShouldQueue
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
            ->subject("Starting now: {$this->event->name}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("\"{$this->event->name}\" is starting now.")
            ->action('Join live', route('live.show'));

        return $this->withFooter($mail);
    }

    public function toArray($notifiable): array
    {
        return ['event_id' => $this->event->id, 'message' => "\"{$this->event->name}\" is starting now."];
    }
}
