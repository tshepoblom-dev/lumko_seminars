<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent when someone who wasn't signed in registered an email that already
 * has a confirmed account (RegistrationController::storeForExistingAccount).
 * The registration is saved, so this is the account owner's confirmation —
 * and, since the submitter never proved who they were, their way to undo
 * it: a signed link, good for 14 days (RegistrationNotMeController).
 * Category: event (transactional).
 */
class RegistrationAddedToAccount extends Notification implements ShouldQueue
{
    use HasTransactionalFooter, Queueable;

    public const CANCEL_LINK_DAYS = 14;

    public function __construct(
        public Event $event,
        public EventRegistration $registration,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function cancelUrl(): string
    {
        return URL::temporarySignedRoute(
            'registration.not-me',
            now()->addDays(self::CANCEL_LINK_DAYS),
            ['registration' => $this->registration->public_id],
        );
    }

    public function toMail($notifiable): MailMessage
    {
        $modeLabel = $this->registration->attendance_mode->label();
        $inPerson = $this->registration->attendance_mode->value === 'in_person';
        $complimentary = $this->registration->isComplimentary();

        $mail = (new MailMessage)
            ->subject("You're registered: {$this->event->name}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("{$notifiable->email} was registered for \"{$this->event->name}\" as a {$modeLabel} attendee, using the registration form without signing in.")
            ->line('Date: '.$this->event->starts_at->format('d F Y, H:i'))
            ->when($inPerson, fn ($mail) => $mail
                ->line("Venue: {$this->event->venue_name}, {$this->event->venue_address}"))
            ->when($inPerson && $complimentary, fn ($mail) => $mail
                ->line('An invitation code was applied: your ticket is complimentary and confirmed.'))
            ->when($inPerson && ! $complimentary, fn ($mail) => $mail
                ->line('Payment instructions are on your dashboard once you log in.'))
            ->line('You already have an account with us, so there is nothing to set up: log in with your usual password to see your registration.')
            ->action('Log in', route('login'))
            ->line('Was this not you? You can cancel this registration within '.self::CANCEL_LINK_DAYS.' days:')
            ->line($this->cancelUrl());

        return $this->withFooter($mail);
    }
}
