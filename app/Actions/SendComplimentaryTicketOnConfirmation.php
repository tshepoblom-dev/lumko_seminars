<?php

namespace App\Actions;

use App\Enums\AttendanceMode;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\TicketConfirmed;

/**
 * A guest who registers with an invitation code gets a confirmed ticket
 * straight away, but no ticket email: their address isn't proven yet (B2),
 * and the ticket page needs a verified account. This sends it — PDF
 * attached — the moment they first verify, by the registration link
 * (ConfirmRegistrationController) or "forgot password"
 * (NewPasswordController). Callers invoke it only when that request did
 * the verifying, not from a Verified listener: changing a profile email
 * re-fires that event and would send it twice. Paid tickets are left out:
 * their PDF goes with PaymentApproved.
 */
class SendComplimentaryTicketOnConfirmation
{
    public function send(User $user): int
    {
        $registrations = EventRegistration::with(['ticket', 'event'])
            ->where('user_id', $user->id)
            ->whereNotNull('voucher_id')
            ->where('attendance_mode', AttendanceMode::InPerson->value)
            ->where('status', 'registered')
            ->get()
            ->filter(fn (EventRegistration $registration) => $registration->ticket?->status?->value === 'confirmed'
                && ! ($registration->event->ends_at?->isPast() ?? false));

        foreach ($registrations as $registration) {
            $user->notify(new TicketConfirmed($registration->ticket));
        }

        return $registrations->count();
    }
}
