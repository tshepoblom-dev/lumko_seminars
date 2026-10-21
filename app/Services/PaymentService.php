<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\PaymentStatusHistory;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\PaymentApproved;
use App\Notifications\PaymentProofReceived;
use App\Notifications\PaymentRejected;
use App\Support\Audit;
use App\Support\TicketCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * B4 — the payment state machine. Every mutation goes through here (not
 * the controllers directly), each wrapped in a transaction with
 * `lockForUpdate()` so two concurrent admin actions (or a resubmit racing
 * an approval) can't leave the payment/ticket in an inconsistent state.
 */
class PaymentService
{
    public function submitProof(Payment $payment, UploadedFile $file, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $file, $actor) {
            /** @var Payment $payment */
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertTransition($payment->status, PaymentStatus::UnderReview);

            $previousProofPath = $payment->proof_path;
            $path = $file->store('payment-proofs', 'local');
            $from = $payment->status->value;

            $payment->update([
                'proof_path' => $path,
                'proof_original_name' => $file->getClientOriginalName(),
                'status' => PaymentStatus::UnderReview,
            ]);

            PaymentStatusHistory::create([
                'payment_id' => $payment->id,
                'from_status' => $from,
                'to_status' => PaymentStatus::UnderReview->value,
                'changed_by' => $actor?->id,
                'note' => $from === PaymentStatus::Rejected->value ? 'Attendee resubmitted proof of payment.' : 'Attendee uploaded proof of payment.',
            ]);

            if ($previousProofPath && $previousProofPath !== $path) {
                Storage::disk('local')->delete($previousProofPath);
                Audit::record('payment.proof_replaced', subject: $payment, before: ['proof_path' => $previousProofPath]);
            }

            $payment->registration->user->notify(new PaymentProofReceived($payment));

            return $payment;
        });
    }

    /**
     * @throws ValidationException if approval is attempted without a
     *                             proof on file and $manualOverride is false.
     */
    public function approve(Payment $payment, ?string $note, bool $manualOverride, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $note, $manualOverride, $actor) {
            /** @var Payment $payment */
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // T-PAY-3 — idempotent: approving an already-approved payment
            // is a safe no-op, not an error and not a duplicate history row.
            if ($payment->status === PaymentStatus::Approved) {
                return $payment;
            }

            $this->assertTransition($payment->status, PaymentStatus::Approved);

            if (! $payment->proof_path && ! $manualOverride) {
                throw ValidationException::withMessages([
                    'proof' => 'This payment has no proof of payment on file. Use "Approve without proof" and provide a note if this was confirmed another way (e.g. bank statement).',
                ]);
            }

            if ($manualOverride && ! $note) {
                throw ValidationException::withMessages([
                    'note' => 'A note is required when approving without a proof on file.',
                ]);
            }

            $from = $payment->status->value;

            $payment->update([
                'status' => PaymentStatus::Approved,
                'admin_note' => $note,
                'manual_override' => $manualOverride,
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now(),
            ]);

            $payment->registration->ticket?->update([
                'status' => TicketStatus::Confirmed,
                'issued_at' => now(),
            ]);

            PaymentStatusHistory::create([
                'payment_id' => $payment->id,
                'from_status' => $from,
                'to_status' => PaymentStatus::Approved->value,
                'changed_by' => $actor?->id,
                'note' => $note ?: 'Payment approved.',
            ]);

            Audit::record(
                'payment.approved', subject: $payment, actor: $actor,
                eventId: $payment->registration?->event_id,
                before: ['status' => $from], after: ['status' => 'approved', 'manual_override' => $manualOverride],
            );

            // After commit: the email carries the PDF ticket, which a worker
            // on another connection must find already confirmed.
            $payment->registration->user->notify((new PaymentApproved($payment))->afterCommit());

            return $payment;
        });
    }

    public function reject(Payment $payment, string $note, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $note, $actor) {
            /** @var Payment $payment */
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertTransition($payment->status, PaymentStatus::Rejected);

            $from = $payment->status->value;

            $payment->update([
                'status' => PaymentStatus::Rejected,
                'admin_note' => $note,
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now(),
            ]);

            // T-PAY-2 — ticket goes back to pending, never stays confirmed.
            $payment->registration->ticket?->update(['status' => TicketStatus::Pending, 'issued_at' => null]);

            PaymentStatusHistory::create([
                'payment_id' => $payment->id,
                'from_status' => $from,
                'to_status' => PaymentStatus::Rejected->value,
                'changed_by' => $actor?->id,
                'note' => $note,
            ]);

            Audit::record(
                'payment.rejected', subject: $payment, actor: $actor,
                eventId: $payment->registration?->event_id,
                before: ['status' => $from], after: ['status' => 'rejected'],
            );

            $payment->registration->user->notify(new PaymentRejected($payment, $note));

            return $payment;
        });
    }

    public function cancel(Payment $payment, string $note, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $note, $actor) {
            /** @var Payment $payment */
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertTransition($payment->status, PaymentStatus::Cancelled);

            $from = $payment->status->value;

            $payment->update([
                'status' => PaymentStatus::Cancelled,
                'admin_note' => $note,
                'reviewed_by' => $actor?->id,
                'cancelled_at' => now(),
            ]);

            // T-PAY-2 — cancel/void always drops the ticket to cancelled.
            $payment->registration->ticket?->update(['status' => TicketStatus::Cancelled]);

            PaymentStatusHistory::create([
                'payment_id' => $payment->id,
                'from_status' => $from,
                'to_status' => PaymentStatus::Cancelled->value,
                'changed_by' => $actor?->id,
                'note' => $note,
            ]);

            Audit::record(
                'payment.cancelled', subject: $payment, actor: $actor,
                eventId: $payment->registration?->event_id,
                before: ['status' => $from], after: ['status' => 'cancelled'],
            );

            return $payment;
        });
    }

    /**
     * A complimentary in-person seat (an invitation code — VoucherService):
     * the payment becomes an approved R0 one and the ticket is confirmed in
     * the same step. Its own entry point rather than a new canTransitionTo()
     * edge, so admins still can't approve a rejected payment directly. Only
     * "nothing paid yet" may be waived — no payment row, Pending or Rejected
     * — never one under review (money may be on its way), approved or
     * cancelled. Sends nothing: the caller knows which email fits.
     */
    public function waive(EventRegistration $registration, string $note, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($registration, $note, $actor) {
            /** @var Payment|null $payment */
            $payment = Payment::where('event_registration_id', $registration->id)->lockForUpdate()->first();
            $from = $payment?->status;

            if ($from !== null && ! in_array($from, [PaymentStatus::Pending, PaymentStatus::Rejected], true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot waive a payment that is \"{$from->label()}\".",
                ]);
            }

            $previousAmount = $payment?->amount;
            $payment ??= new Payment(['event_registration_id' => $registration->id]);
            $payment->fill([
                'amount' => 0,
                'status' => PaymentStatus::Approved,
                'admin_note' => $note,
                'manual_override' => true,
                'reviewed_by' => null,
                'reviewed_at' => now(),
            ])->save();

            $ticket = Ticket::firstOrNew(['event_registration_id' => $registration->id]);
            if (! $ticket->exists) {
                $ticket->code = TicketCode::generate();
            }
            $ticket->fill(['status' => TicketStatus::Confirmed, 'issued_at' => now()])->save();

            PaymentStatusHistory::create([
                'payment_id' => $payment->id,
                'from_status' => $from?->value,
                'to_status' => PaymentStatus::Approved->value,
                'changed_by' => $actor?->id,
                'note' => $note,
            ]);

            Audit::record(
                'payment.waived', subject: $payment, actor: $actor,
                eventId: $registration->event_id,
                before: ['status' => $from?->value, 'amount' => $previousAmount],
                after: ['status' => 'approved', 'amount' => '0.00'],
            );

            return $payment;
        });
    }

    private function assertTransition(PaymentStatus $from, PaymentStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot move a payment from \"{$from->label()}\" to \"{$to->label()}\".",
            ]);
        }
    }
}
