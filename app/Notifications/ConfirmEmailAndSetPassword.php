<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * B2 — sent instead of logging the visitor in directly. The link both
 * verifies the email and lets them set a password (ConfirmRegistrationController).
 * Consent recorded at registration only becomes effective once this link
 * is used (ConsentService::markPendingEffective). Category: event (not
 * marketing) — see App\Notifications\Concerns\GuardsMarketingConsent.
 */
class ConfirmEmailAndSetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    /** $complimentary: their registration was made with an invitation code. */
    public function __construct(
        public Event $event,
        public string $token,
        public bool $complimentary = false,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = route('registration.confirm', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject("Confirm your registration: {$this->event->name}")
            ->greeting("Hi {$notifiable->first_name},")
            ->line("Thanks for registering for \"{$this->event->name}\". Confirm your email and set a password to finish setting up your account.")
            ->when($this->complimentary, fn ($mail) => $mail
                ->line('Your invitation code has been applied: your in-person ticket is complimentary and confirmed. Once you have confirmed your email, we will email you the ticket as a PDF, and it will be in your account.'))
            ->action('Confirm email & set password', $url)
            ->line('This link expires in 24 hours. If it expires, you can request a new one from the login page ("forgot password").')
            ->line("If you didn't request this, you can safely ignore this email.")
            ->line('—')
            ->line(config('seminar.organiser_legal_name'))
            ->line('Privacy Policy: '.(config('seminar.privacy_notice_url') ?: route('legal.privacy')).' · Terms: '.route('legal.terms'));
    }
}
