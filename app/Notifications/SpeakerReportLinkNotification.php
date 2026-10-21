<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent on demand to a speaker's contact_email from Event → Speakers. Not
 * queued: the admin sees straight away whether it went. It carries the only
 * copy of the raw link token.
 */
class SpeakerReportLinkNotification extends Notification
{
    public function __construct(
        public string $speakerName,
        public string $eventName,
        public string $url,
        public CarbonInterface $expiresAt,
        public int $contactCount,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $firstName = explode(' ', trim($this->speakerName))[0] ?: $this->speakerName;
        $people = $this->contactCount === 1 ? '1 person has' : "{$this->contactCount} people have";

        $mail = (new MailMessage)
            ->subject("Your {$this->eventName} speaker report")
            ->greeting("Hi {$firstName},")
            ->line("Thank you for speaking at {$this->eventName}.")
            ->line("{$people} asked to connect with you so far. Each of them chose to share their details with you personally; nobody was added without asking.")
            ->line('Your report also shows the questions, chat and reactions from your session, your poll results, and a summary of the audience.')
            ->action('Open your report', $this->url)
            ->line('The link is private to you and works until '.$this->expiresAt->format('j F Y').'. Please don\'t forward it. The list stays up to date: anyone who withdraws is removed.');

        // Speakers have no account, so not the attendee footer's
        // "manage your preferences" link.
        return $mail
            ->line('—')
            ->line(config('seminar.organiser_legal_name'))
            ->line('Privacy Policy: '.(config('seminar.privacy_notice_url') ?: route('legal.privacy')));
    }
}
