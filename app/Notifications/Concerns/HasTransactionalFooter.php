<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * B14 — every event/transactional email carries a consistent footer: the
 * organiser's legal name and a link to /account for preferences. Applied
 * as the last step of toMail() in every Notification class, so a manual
 * `$mail->line(...)` chain in each class can't forget it.
 */
trait HasTransactionalFooter
{
    protected function withFooter(MailMessage $mail): MailMessage
    {
        return $mail
            ->line('—')
            ->line(config('seminar.organiser_legal_name'))
            ->line('Manage your email preferences any time from your account: '.route('attendee.preferences.edit'))
            ->line('Privacy Policy: '.(config('seminar.privacy_notice_url') ?: route('legal.privacy')).' · Terms: '.route('legal.terms'));
    }
}
