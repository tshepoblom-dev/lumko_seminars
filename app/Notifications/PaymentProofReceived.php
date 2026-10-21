<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\HasTransactionalFooter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentProofReceived extends Notification implements ShouldQueue
{
    use HasTransactionalFooter, Queueable;

    public function __construct(public Payment $payment) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('We received your proof of payment')
            ->greeting("Hi {$notifiable->first_name},")
            ->line('Thanks — your proof of payment has been submitted and is now under review.')
            ->line("We'll email you as soon as it's been checked, usually within a couple of days.")
            ->action('View payment status', route('payment.show'));

        return $this->withFooter($mail);
    }

    public function toArray($notifiable): array
    {
        return ['payment_id' => $this->payment->id, 'message' => 'Your proof of payment was received and is under review.'];
    }
}
