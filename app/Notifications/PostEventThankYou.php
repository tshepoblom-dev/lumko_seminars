<?php

namespace App\Notifications;

use App\Models\Event;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PostEventThankYou extends Notification implements ShouldQueue
{
    use HasTransactionalFooter, Queueable;

    public function __construct(public Event $event) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        // MailMessage only renders one action button; the recording link
        // (when present) is a plain line so "Give feedback" stays the one
        // clear call to action.
        $mail = (new MailMessage)
            ->subject("Thank you for attending {$this->event->name}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("Thanks for being part of \"{$this->event->name}\".")
            ->when($this->event->recording_url, fn ($mail) => $mail->line('Recording: '.$this->event->recording_url))
            ->line("We'd love to hear what you thought.")
            ->action('Give feedback', route('feedback.create'));

        return $this->withFooter($mail);
    }

    public function toArray($notifiable): array
    {
        return ['event_id' => $this->event->id, 'message' => "Thanks for attending \"{$this->event->name}\" — share your feedback."];
    }
}
