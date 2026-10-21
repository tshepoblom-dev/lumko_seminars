<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentRejected extends Notification implements ShouldQueue
{
    use HasTransactionalFooter, Queueable;

    public function __construct(public Payment $payment, public string $note) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your proof of payment could not be confirmed')
            ->greeting("Hi {$notifiable->first_name},")
            ->line('We were unable to confirm your proof of payment:')
            ->line($this->note)
            ->line('You can upload a new proof of payment at any time.')
            ->action('Resubmit proof of payment', route('payment.show'));

        return $this->withFooter($mail);
    }

    public function toArray($notifiable): array
    {
        return ['payment_id' => $this->payment->id, 'message' => 'Your proof of payment was rejected: '.$this->note];
    }
}
