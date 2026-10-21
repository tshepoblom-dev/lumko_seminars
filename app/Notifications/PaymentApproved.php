<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\AttachesTicketPdf;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Carries the PDF ticket: approval is the moment a paid ticket becomes valid. */
class PaymentApproved extends Notification implements ShouldQueue
{
    use AttachesTicketPdf, HasTransactionalFooter, Queueable;

    public function __construct(public Payment $payment) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Payment approved — your ticket is confirmed')
            ->greeting("Hi {$notifiable->first_name},")
            ->line('Your payment has been approved and your ticket is now confirmed.')
            ->action('View your ticket', route('ticket.show'));

        return $this->withFooter($this->attachTicketPdf($mail, $this->payment->registration?->ticket));
    }

    public function toArray($notifiable): array
    {
        return ['payment_id' => $this->payment->id, 'message' => 'Your payment was approved and your ticket is confirmed.'];
    }
}
