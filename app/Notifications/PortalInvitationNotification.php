<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An invitation to the speaker or partner portal (PortalInvitationService).
 * Not queued: the admin or partner owner sees straight away whether it
 * went. It carries the only copy of the raw invitation token.
 */
class PortalInvitationNotification extends Notification
{
    public function __construct(
        public string $kind,
        public string $subjectName,
        public string $inviterName,
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->kind === 'speaker'
            ? 'Your Lumko Seminars speaker portal'
            : "Join {$this->subjectName} on Lumko Seminars");

        if ($this->kind === 'speaker') {
            $firstName = explode(' ', trim($this->subjectName))[0] ?: $this->subjectName;
            $mail->greeting("Hi {$firstName},")
                ->line('You have a speaker portal on Lumko Seminars. Sign in to see your sessions, share slides and resources with attendees, test your camera and microphone on the livestream, and see who asked to connect with you.');
        } else {
            $mail->greeting('Hello,')
                ->line("{$this->inviterName} invited you to {$this->subjectName}'s partner portal on Lumko Seminars, where your team manages your partner page and the people who chose to connect with you.");
        }

        return $mail
            ->action('Accept the invitation', $this->url)
            ->line('The link works once, until '.$this->expiresAt->format('j F Y').'. If you already have an account with this email address, you will be asked to log in.')
            ->line('If you were not expecting this, you can ignore this email.')
            ->line('—')
            ->line(config('seminar.organiser_legal_name'))
            ->line('Privacy Policy: '.(config('seminar.privacy_notice_url') ?: route('legal.privacy')));
    }
}
