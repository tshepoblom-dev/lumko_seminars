<?php

namespace App\Notifications;

use App\Models\Event;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A nudge from staff (Admin → Attendees) to someone who has an account but
 * isn't registered for the event: creating an account doesn't register
 * anyone, and people have assumed it did. Sent by hand, never on a
 * schedule. Category: event (about their own unfinished registration).
 */
class RegisterForEventReminder extends Notification implements ShouldQueue
{
    use HasTransactionalFooter, Queueable;

    public function __construct(public Event $event) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("You're not registered yet: {$this->event->name}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("You have an account with us, but you haven't registered for \"{$this->event->name}\" yet. Having an account doesn't reserve your place.")
            ->line('Date: '.$this->event->starts_at->format('d F Y, H:i'))
            ->line('Registering takes a couple of minutes. If you log in first, your name and email are filled in for you.')
            ->action('Register for the event', route('event.register'))
            ->line("If you no longer want to attend, you can ignore this email.");

        return $this->withFooter($mail);
    }
}
