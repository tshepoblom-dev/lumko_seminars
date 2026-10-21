<?php

namespace App\Http\Controllers\Public;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\RegistrationAnswer;
use App\Models\Ticket;
use App\Services\ConsentService;
use App\Services\VoucherService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "This wasn't me": the signed link in RegistrationAddedToAccount, sent
 * when someone who wasn't signed in registered an email that already had a
 * confirmed account. No login: the signed link (14 days) is the credential,
 * and both routes sit behind the `signed` middleware.
 *
 * Cancelling removes the registration and everything it created, but only
 * while nothing has been done with it: no check-in, no proof of payment
 * and no payment decision by staff. After that the organisers handle it.
 */
class RegistrationNotMeController extends Controller
{
    public function show(string $registration): Response
    {
        $found = $this->find($registration);

        return Inertia::render('Public/RegistrationNotMe', [
            'state' => ! $found ? 'gone' : ($this->canCancel($found) ? 'confirm' : 'blocked'),
            'registration' => $found ? [
                'event' => $found->event->name,
                'attendance_mode' => $found->attendance_mode->label(),
                'registered_on' => $found->created_at->format('j F Y'),
            ] : null,
        ]);
    }

    public function destroy(string $registration, ConsentService $consents, VoucherService $vouchers): RedirectResponse
    {
        $found = $this->find($registration);

        if (! $found || ! $this->canCancel($found)) {
            return back();
        }

        DB::transaction(function () use ($found, $consents, $vouchers) {
            $user = $found->user;

            // Consent given on that form never took effect; make sure a
            // later sign-in can't switch it on.
            $consents->voidPending($user, 'registration_cancelled');

            $vouchers->release($found, $user);

            foreach (RegistrationAnswer::where('event_registration_id', $found->id)->get() as $answer) {
                if ($path = $answer->filePath()) {
                    Storage::disk('local')->delete($path);
                }
                $answer->delete();
            }

            Ticket::where('event_registration_id', $found->id)->delete();
            Payment::where('event_registration_id', $found->id)->get()->each->delete();

            Audit::record('registration.cancelled_not_me', subject: $found, actor: $user, eventId: $found->event_id, before: [
                'user_id' => $user->id,
                'attendance_mode' => $found->attendance_mode->value,
                'registered_at' => $found->created_at->toIso8601String(),
            ]);

            $found->delete();
        });

        return redirect()->route('registration.not-me.done');
    }

    public function done(): Response
    {
        return Inertia::render('Public/RegistrationNotMe', ['state' => 'cancelled']);
    }

    private function find(string $publicId): ?EventRegistration
    {
        return EventRegistration::with(['event', 'user', 'payment', 'ticket.checkIn'])->where('public_id', $publicId)->first();
    }

    /** Nothing has been done with it yet: not checked in, nothing paid or decided. */
    private function canCancel(EventRegistration $registration): bool
    {
        if ($registration->ticket?->checkIn) {
            return false;
        }

        $payment = $registration->payment;

        if (! $payment || $registration->isComplimentary()) {
            return true;
        }

        return $payment->status === PaymentStatus::Pending && blank($payment->proof_path);
    }
}
