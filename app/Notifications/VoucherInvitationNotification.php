<?php

namespace App\Notifications;

use App\Models\Voucher;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An honorary invitation, sent on demand from Event → Vouchers to the
 * recipient's address (who may have no account yet). Not queued: the admin
 * sees straight away whether it went, and the raw code — which this email
 * carries — never sits in the jobs table.
 */
class VoucherInvitationNotification extends Notification
{
    public function __construct(public Voucher $voucher) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $event = $this->voucher->event;
        $code = $this->voucher->code;
        $firstName = explode(' ', trim($this->voucher->recipient_name))[0] ?: $this->voucher->recipient_name;
        $where = $event->venue_name ? " at {$event->venue_name}".($event->venue_address ? ", {$event->venue_address}" : '') : '';

        $mail = (new MailMessage)
            ->subject("You're invited: {$event->name}")
            ->greeting("Dear {$firstName},")
            ->line("We would be honoured to have you as our guest at {$event->name}.")
            ->line('It takes place on '.$event->starts_at->format('j F Y \a\t H:i')."{$where}.")
            ->line('Your in-person ticket is complimentary. Claim it with your invitation code:')
            ->line("**{$code}**")
            ->action('Claim your ticket', route('event.register', ['code' => $code]))
            ->line($this->voucher->email_locked
                ? "The code is reserved for you, so please register with {$this->voucher->recipient_email}."
                : 'The code can be used once.')
            ->line('Already have an account with us? [Sign in and claim your ticket]('.route('voucher.redeem', ['code' => $code]).').');

        // The recipient may have no account, so not the attendee footer's
        // "manage your preferences" link.
        return $mail
            ->line('—')
            ->line(config('seminar.organiser_legal_name'))
            ->line('Privacy Policy: '.(config('seminar.privacy_notice_url') ?: route('legal.privacy')));
    }
}
