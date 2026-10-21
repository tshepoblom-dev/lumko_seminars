<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApprovePaymentRequest;
use App\Http\Requests\Admin\CancelPaymentRequest;
use App\Http\Requests\Admin\RejectPaymentRequest;
use App\Http\Responses\SafeFileResponse;
use App\Models\Event;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function index(Event $event): Response
    {
        $this->authorize('viewAny', Payment::class);
        // B11 — an event manager sees only payments for events they're
        // assigned to.
        $this->authorize('view', $event);

        return Inertia::render('Admin/Payments/Index', [
            'payments' => Payment::with('registration.user')
                ->whereHas('registration', fn ($q) => $q->where('event_id', $event->id))
                ->latest('payments.created_at')
                ->get()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'attendee' => $payment->registration->user->name,
                    'organisation' => $payment->registration->user->organisation,
                    'amount' => (float) $payment->amount,
                    'status' => $payment->status->value,
                    'status_label' => $payment->status->label(),
                    'proof_uploaded' => (bool) $payment->proof_path,
                    // Waived with an invitation code (Event → Vouchers).
                    'complimentary' => $payment->registration->isComplimentary(),
                ]),
        ]);
    }

    public function show(Event $event, Payment $payment): Response
    {
        $this->authorize('view', $payment);

        $payment->load(['registration.user', 'registration.voucher', 'statusHistory.changedBy']);

        return Inertia::render('Admin/Payments/Show', [
            'payment' => [
                'id' => $payment->id,
                'complimentary' => $payment->registration->isComplimentary(),
                'voucher_recipient' => $payment->registration->voucher?->recipient_name,
                'amount' => (float) $payment->amount,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'admin_note' => $payment->admin_note,
                'proof_uploaded' => (bool) $payment->proof_path,
                'manual_override' => $payment->manual_override,
                'attendee' => [
                    'name' => $payment->registration->user->name,
                    'email' => $payment->registration->user->email,
                    'organisation' => $payment->registration->user->organisation,
                ],
                'history' => $payment->statusHistory->map(fn ($h) => [
                    'from' => $h->from_status,
                    'to' => $h->to_status,
                    'note' => $h->note,
                    'changed_by' => $h->changedBy?->name ?? 'System',
                    'at' => $h->created_at->format('d M Y H:i'),
                ]),
            ],
        ]);
    }

    public function proof(Event $event, Payment $payment): StreamedResponse
    {
        $this->authorize('view', $payment);

        // B13 — PoP views/downloads are audited; never log the file's
        // contents, only that it was looked at, by whom, when.
        Audit::record('payment.proof_viewed', subject: $payment, eventId: $payment->registration?->event_id);

        // B3 — inline is allowed here (staff-only, image/PDF only); the
        // resource download route never allows inline.
        return SafeFileResponse::make('local', $payment->proof_path, $payment->proof_original_name ?? 'proof', allowInline: true);
    }

    public function approve(ApprovePaymentRequest $request, Event $event, Payment $payment): RedirectResponse
    {
        try {
            $this->payments->approve(
                $payment,
                $request->input('note'),
                $request->boolean('manual_override'),
                $request->user(),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Payment approved and ticket confirmed.');
    }

    public function reject(RejectPaymentRequest $request, Event $event, Payment $payment): RedirectResponse
    {
        try {
            $this->payments->reject($payment, $request->input('note'), $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Payment rejected. The attendee can resubmit proof of payment.');
    }

    public function cancel(CancelPaymentRequest $request, Event $event, Payment $payment): RedirectResponse
    {
        try {
            $this->payments->cancel($payment, $request->input('note'), $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Payment cancelled.');
    }
}
