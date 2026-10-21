<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a partner's team that an attendee sent an enquiry. Deliberately
 * carries no personal data: the details are in the portal, behind login,
 * where withdrawing takes effect at once.
 */
class PartnerEnquiryReceived extends Notification
{
    public function __construct(
        public string $partnerName,
        public string $eventName,
        public string $url,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New enquiry for {$this->partnerName} · {$this->eventName}")
            ->greeting('Hello,')
            ->line("An attendee of {$this->eventName} sent {$this->partnerName} an enquiry and chose to share their contact details with you.")
            ->action('Open your leads', $this->url)
            ->line('For their privacy, the details are only in your portal.');
    }

    public function toArray($notifiable): array
    {
        return [
            'message' => "New enquiry for {$this->partnerName} ({$this->eventName}).",
            'url' => $this->url,
        ];
    }
}
