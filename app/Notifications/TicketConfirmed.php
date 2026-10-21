<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Notifications\Concerns\AttachesTicketPdf;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent with the PDF ticket when an invitation code confirms a ticket
 * (VoucherService) for someone already signed in, or once a guest who
 * redeemed one confirms their email (SendComplimentaryTicketOnConfirmation).
 * Paid tickets get theirs with PaymentApproved instead.
 */
class TicketConfirmed extends Notification implements ShouldQueue
{
    use AttachesTicketPdf, HasTransactionalFooter, Queueable;

    public function __construct(public Ticket $ticket) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your ticket is confirmed')
            ->greeting("Hi {$notifiable->first_name},")
            ->line('Your ticket is confirmed. See you there!')
            ->action('View your ticket', route('ticket.show'));

        return $this->withFooter($this->attachTicketPdf($mail, $this->ticket));
    }

    public function toArray($notifiable): array
    {
        return ['ticket_id' => $this->ticket->id, 'message' => 'Your ticket is confirmed.'];
    }
}
