<?php

namespace App\Http\Controllers\Public;

use App\Actions\StartInPersonPlace;
use App\Enums\AttendanceMode;
use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Notifications\RegistrationConfirmed;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * An online registrant moves to in person from their dashboard (owner
 * decision 2026-10-21: The Power of Data became in person only, and people
 * who had registered online keep their registration and may switch). The
 * place starts exactly as an in-person registration does: a pending
 * payment for the in-person price and a pending ticket (StartInPersonPlace).
 * An invitation code (/redeem) is the other way across, and is free.
 */
class SwitchToInPersonController extends Controller
{
    public function __invoke(StartInPersonPlace $place): RedirectResponse
    {
        $user = Auth::user();

        $registration = EventRegistration::with(['event', 'payment'])
            ->where('user_id', $user->id)
            ->whereHas('event', fn ($q) => $q->where('ends_at', '>=', now()))
            ->latest()
            ->first();

        if (! $registration) {
            return redirect()->route('attendee.dashboard')
                ->with('info', 'There is no upcoming registration to change.');
        }

        if ($registration->attendance_mode === AttendanceMode::InPerson) {
            return redirect()->route('payment.show')
                ->with('info', "You're already registered to attend in person.");
        }

        // A payment on an online registration means staff have been
        // involved; don't guess what it should become.
        if ($registration->payment) {
            return redirect()->route('attendee.dashboard')
                ->with('info', 'We need to make this change for you. Please reply to one of our emails and we\'ll sort it out.');
        }

        DB::transaction(function () use ($registration, $place) {
            $registration = EventRegistration::whereKey($registration->id)->lockForUpdate()->with('event')->firstOrFail();

            // A second click while the first was still running.
            if ($registration->attendance_mode === AttendanceMode::InPerson
                || Payment::where('event_registration_id', $registration->id)->exists()) {
                return;
            }

            $registration->forceFill(['attendance_mode' => AttendanceMode::InPerson])->save();
            $place->start($registration, 'Switched from online to in person.');

            Audit::record('registration.switched_to_in_person', subject: $registration, eventId: $registration->event_id, after: [
                'amount' => (string) $registration->event->in_person_price,
            ]);
        });

        $registration->refresh()->load('event');
        $user->notify(new RegistrationConfirmed($registration->event, $registration));

        return redirect()->route('payment.show')
            ->with('success', "You're now registered to attend in person. Here's how to pay for your place.");
    }
}
