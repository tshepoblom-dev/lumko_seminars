<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * B12 — sent to super admins when an attendee deletes (anonymises) their
 * own account, so staff know why a name in their records has changed to
 * "Deleted user" rather than assuming data corruption.
 */
class AccountAnonymised extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $anonymisedUserId) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('An attendee deleted their account')
            ->line("User #{$this->anonymisedUserId} requested account deletion and has been anonymised.")
            ->line('Their registration, payment and consent records are retained without personal information, as required for financial and compliance records.');
    }
}
