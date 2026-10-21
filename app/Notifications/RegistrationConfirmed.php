<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Notifications\Concerns\AttachesTicketPdf;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationConfirmed extends Notification implements ShouldQueue
{
    use AttachesTicketPdf, HasTransactionalFooter, Queueable;

    public function __construct(
        public Event $event,
        public EventRegistration $registration,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $modeLabel = $this->registration->attendance_mode->label();
        $inPerson = $this->registration->attendance_mode->value === 'in_person';
        $complimentary = $this->registration->isComplimentary();

        $mail = (new MailMessage)
            ->subject("You're registered: {$this->event->name}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("You're confirmed to attend \"{$this->event->name}\" as a {$modeLabel} attendee.")
            ->line('Date: '.$this->event->starts_at->format('d F Y, H:i'))
            ->when($inPerson, fn ($mail) => $mail
                ->line("Venue: {$this->event->venue_name}, {$this->event->venue_address}"))
            ->when($inPerson && $complimentary, fn ($mail) => $mail
                ->line('Your invitation code has been applied: your ticket is complimentary and confirmed.')
                ->action('View your ticket', route('ticket.show')))
            ->when($inPerson && ! $complimentary, fn ($mail) => $mail
                ->action('View payment instructions', route('payment.show')))
            ->line('We\'ll be in touch with programme details and reminders as the event approaches.');

        // An invitation code confirms the ticket at once, so it goes with this email.
        if ($inPerson && $complimentary) {
            $this->attachTicketPdf($mail, $this->registration->ticket()->first());
        }

        return $this->withFooter($mail);
    }
}
