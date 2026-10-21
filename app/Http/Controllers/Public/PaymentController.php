<?php

namespace App\Http\Controllers\Public;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StorePaymentProofRequest;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function show(): Response|RedirectResponse
    {
        $registration = Auth::user()->registrations()
            ->with(['payment', 'event'])
            ->where('attendance_mode', 'in_person')
            ->latest()
            ->firstOrFail();

        // Complimentary (an invitation code): nothing to pay, so no bank
        // details — straight to the ticket.
        if ($registration->isComplimentary() && $registration->payment?->status === PaymentStatus::Approved) {
            return redirect()->route('ticket.show');
        }

        return Inertia::render('Public/Payment', [
            'event' => [
                'name' => $registration->event->name,
                // Display only.
                'starts_at' => $registration->event->starts_at?->toIso8601String(),
                'ends_at' => $registration->event->ends_at?->toIso8601String(),
                'venue_name' => $registration->event->venue_name,
            ],
            'payment' => [
                'amount' => (float) $registration->payment->amount,
                'status' => $registration->payment->status->value,
                'status_label' => $registration->payment->status->label(),
                'admin_note' => $registration->payment->status->value === 'rejected'
                    ? $registration->payment->admin_note
                    : null,
                'proof_uploaded' => (bool) $registration->payment->proof_path,
            ],
            'bankDetails' => $this->bankDetails($registration->id),
        ]);
    }

    /**
     * Bank details come from configuration (.env). If any required field is
     * missing the page shows a "details coming" notice instead of a
     * half-filled account, so an attendee never pays into a wrong/blank account.
     */
    private function bankDetails(int $registrationId): array
    {
        $config = config('seminar.payment');

        $required = ['account_name', 'bank', 'account_number', 'branch_code'];
        $configured = collect($required)->every(fn ($key) => filled($config[$key] ?? null));

        return [
            'configured' => $configured,
            'account_name' => $config['account_name'],
            'bank' => $config['bank'],
            'account_number' => $config['account_number'],
            'branch_code' => $config['branch_code'],
            'account_type' => $config['account_type'],
            'reference' => ($config['reference_prefix'] ?? 'EVENT-').$registrationId,
        ];
    }

    public function store(StorePaymentProofRequest $request): RedirectResponse
    {
        $registration = Auth::user()->registrations()
            ->where('attendance_mode', 'in_person')
            ->latest()
            ->firstOrFail();

        $payment = $registration->payment;

        abort_if(! $payment, 404);

        // B3/B4 — may only upload while pending/rejected/under_review;
        // never once approved or cancelled. PaymentService enforces this
        // as part of the state machine (assertTransition), so a stale
        // "approved" payment can't accept a new proof even in a race.
        abort_if(
            in_array($payment->status, [PaymentStatus::Approved, PaymentStatus::Cancelled], true),
            403,
            'This payment can no longer accept a new proof of payment.'
        );

        $this->payments->submitProof($payment, $request->file('proof'), $request->user());

        return redirect()->route('payment.show')->with('info', 'Your proof of payment has been submitted for review.');
    }
}
