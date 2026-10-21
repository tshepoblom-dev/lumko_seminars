<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * B2 — sent when someone who isn't signed in submits /attend with an email
 * whose *verified* account is already registered for the event. Nothing
 * about that registration is changed by an anonymous submission (their
 * identity isn't proven), and the HTTP response to the submitter stays the
 * uniform "check your inbox" message either way — this notification is the
 * only place the distinction is visible, and only to the account owner.
 *
 * (An account that isn't registered yet is registered and gets
 * RegistrationAddedToAccount instead — owner decision 2026-10-13.)
 */
class ExistingAccountRegistrationAttempt extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * $hadInvitationCode: the submission carried a valid invitation code,
     * which was not used — it's only redeemed once the account owner signs
     * in. The code itself is never repeated here.
     */
    public function __construct(public Event $event, public bool $hadInvitationCode = false) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're already registered for {$this->event->name}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("Someone (hopefully you) tried to register {$notifiable->email} for \"{$this->event->name}\", but you are already registered. Nothing has been changed.")
            ->action('Log in to see your registration', route('login'))
            ->when($this->hadInvitationCode, fn ($mail) => $mail
                ->line('An invitation code was entered too. It has not been used yet: sign in and enter it at '.route('voucher.redeem').' to claim your complimentary ticket.'))
            ->line("If this wasn't you, no action is needed — your account is unaffected.")
            ->line('—')
            ->line(config('seminar.organiser_legal_name'));
    }
}
