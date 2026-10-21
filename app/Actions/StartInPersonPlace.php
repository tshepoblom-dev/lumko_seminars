<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\PaymentStatusHistory;
use App\Models\Ticket;
use Illuminate\Support\Str;

/**
 * What an in-person place starts with when it isn't covered by an
 * invitation code: a pending payment for the event's in-person price, a
 * pending ticket, and the first line of the payment's history. Used when
 * someone registers in person (RegistrationController) and when an online
 * registrant switches to in person (SwitchToInPersonController), so the two
 * can never start a place differently. Run it inside the caller's
 * transaction.
 */
class StartInPersonPlace
{
    public function start(EventRegistration $registration, string $note = 'Registration created.'): Payment
    {
        $payment = Payment::create([
            'event_registration_id' => $registration->id,
            'amount' => $registration->event->in_person_price,
            'status' => PaymentStatus::Pending,
        ]);

        Ticket::firstOrCreate(
            ['event_registration_id' => $registration->id],
            ['code' => Str::random(40), 'status' => TicketStatus::Pending],
        );

        PaymentStatusHistory::create([
            'payment_id' => $payment->id,
            'from_status' => null,
            'to_status' => PaymentStatus::Pending->value,
            'note' => $note,
        ]);

        return $payment;
    }
}
