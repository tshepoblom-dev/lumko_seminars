<?php

namespace App\Services;

use App\Enums\AttendanceMode;
use App\Enums\PaymentStatus;
use App\Exceptions\VoucherNotApplicable;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\VoucherInvitationNotification;
use App\Support\Audit;
use App\Support\VoucherCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Honorary invitation codes: one complimentary in-person ticket each.
 * Errors are keyed on `voucher_code`, the field name on both the
 * registration form and the /redeem page. The raw code is never written to
 * the audit log.
 */
class VoucherService
{
    public function __construct(private PaymentService $payments) {}

    public function issue(Event $event, array $data, User $actor): Voucher
    {
        $email = filled($data['recipient_email'] ?? null) ? strtolower(trim($data['recipient_email'])) : null;

        $voucher = new Voucher([
            'event_id' => $event->id,
            // Set when a partner issues it from its portal (Partner\TicketController).
            'partner_id' => $data['partner_id'] ?? null,
            'recipient_name' => $data['recipient_name'],
            'recipient_email' => $email,
            'email_locked' => $email !== null && ! empty($data['email_locked']),
            'note' => $data['note'] ?? null,
            'created_by' => $actor->id,
        ]);

        do {
            $code = VoucherCode::generate();
        } while (Voucher::where('code_hash', VoucherCode::hash($code))->exists());

        $voucher->code = $code;
        $voucher->save();

        Audit::record(
            'voucher.created', subject: $voucher, actor: $actor, eventId: $event->id,
            after: ['recipient_name' => $voucher->recipient_name, 'email_locked' => $voucher->email_locked],
        );

        return $voucher;
    }

    /**
     * Emails the invitation now, not queued: the admin sees straight away
     * whether it went, and the raw code never sits in the jobs table.
     *
     * @throws \Throwable when the mailer refuses it — nothing is marked sent.
     */
    public function send(Voucher $voucher, User $actor): void
    {
        Notification::route('mail', $voucher->recipient_email)
            ->notifyNow(new VoucherInvitationNotification($voucher));

        $voucher->forceFill(['sent_at' => now()])->save();

        Audit::record(
            'voucher.sent', subject: $voucher, actor: $actor, eventId: $voucher->event_id,
            after: ['to' => $voucher->recipient_email],
        );
    }

    /** False when it was already redeemed or revoked (the mirror of redeem()'s claim). */
    public function revoke(Voucher $voucher, User $actor): bool
    {
        $revoked = Voucher::whereKey($voucher->id)
            ->whereNull('redeemed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        if ($revoked) {
            Audit::record('voucher.revoked', subject: $voucher, actor: $actor, eventId: $voucher->event_id);
        }

        return (bool) $revoked;
    }

    /**
     * The code as typed → its voucher, or a validation error. Every message
     * depends only on the code and the email given, never on whether that
     * email has an account. A code for another event reads as unknown.
     *
     * @throws ValidationException
     */
    public function resolve(string $rawCode, ?string $email, ?Event $event = null): Voucher
    {
        $code = VoucherCode::normalise($rawCode);
        $voucher = strlen($code) === 12
            ? Voucher::with('event')->where('code_hash', VoucherCode::hash($code))->first()
            : null;

        if (! $voucher || ($event && (int) $voucher->event_id !== (int) $event->id)) {
            $this->fail("We don't recognise that invitation code. Please check it and try again.");
        }

        if ($voucher->redeemed_at !== null) {
            $this->fail('This invitation code has already been used.');
        }

        if ($voucher->revoked_at !== null || ! $voucher->event->isRegistrationOpen()) {
            $this->fail('This invitation code is no longer valid.');
        }

        if ($voucher->email_locked && strcasecmp((string) $voucher->recipient_email, trim((string) $email)) !== 0) {
            $this->fail('This invitation code was issued to a different email address. Please register with the address your invitation was sent to.');
        }

        return $voucher;
    }

    /**
     * Makes the registration's in-person ticket complimentary and confirms
     * it: an online registration moves to in person, the payment is waived
     * (PaymentService::waive) and the code is used up — all or nothing.
     * Runs inside the caller's transaction when there is one.
     *
     * @throws VoucherNotApplicable when this registration can't take it
     * @throws ValidationException when the code was used or revoked meanwhile
     */
    public function redeem(Voucher $voucher, EventRegistration $registration, User $actor): Ticket
    {
        return DB::transaction(function () use ($voucher, $registration, $actor) {
            /** @var EventRegistration $registration */
            $registration = EventRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();

            $this->assertApplicable($voucher, $registration);

            // The claim itself: a conditional update, so two submits (or a
            // revoke racing a redeem) can't both win — without relying on
            // row locks, which SQLite ignores.
            $claimed = Voucher::whereKey($voucher->id)
                ->whereNull('redeemed_at')
                ->whereNull('revoked_at')
                ->update(['redeemed_at' => now(), 'updated_at' => now()]);

            if ($claimed !== 1) {
                $this->fail(Voucher::whereKey($voucher->id)->whereNotNull('revoked_at')->exists()
                    ? 'This invitation code is no longer valid.'
                    : 'This invitation code has already been used.');
            }

            $switchedFromOnline = $registration->attendance_mode !== AttendanceMode::InPerson;

            $registration->forceFill([
                'attendance_mode' => AttendanceMode::InPerson,
                'voucher_id' => $voucher->id,
            ])->save();

            $this->payments->waive($registration, 'Complimentary ticket — invitation code redeemed.', $actor);

            Audit::record(
                'voucher.redeemed', subject: $voucher, actor: $actor, eventId: $voucher->event_id,
                after: ['event_registration_id' => $registration->id, 'switched_from_online' => $switchedFromOnline],
            );

            return Ticket::where('event_registration_id', $registration->id)->firstOrFail();
        });
    }

    /**
     * Frees the code a registration used, so it can be redeemed again: the
     * registration is being cancelled as "not me"
     * (RegistrationNotMeController), which also removes its waived payment
     * and ticket. Does nothing for a registration without a code.
     */
    public function release(EventRegistration $registration, ?User $actor = null): void
    {
        if ($registration->voucher_id === null) {
            return;
        }

        $voucher = Voucher::find($registration->voucher_id);
        $registration->forceFill(['voucher_id' => null])->save();

        if ($voucher) {
            Voucher::whereKey($voucher->id)->update(['redeemed_at' => null, 'updated_at' => now()]);

            Audit::record(
                'voucher.released', subject: $voucher, actor: $actor, eventId: $voucher->event_id,
                after: ['event_registration_id' => $registration->id],
            );
        }
    }

    private function assertApplicable(Voucher $voucher, EventRegistration $registration): void
    {
        $refuse = fn (string $message) => throw VoucherNotApplicable::withMessages(['voucher_code' => $message]);

        if ((int) $registration->event_id !== (int) $voucher->event_id) {
            $refuse('That invitation code is for a different event.');
        }

        if ($registration->voucher_id !== null) {
            $refuse('Your ticket is already complimentary — there is nothing more to redeem.');
        }

        if ($registration->status !== 'registered') {
            $refuse('This registration can no longer be changed. Please contact the organisers.');
        }

        $paymentStatus = Payment::where('event_registration_id', $registration->id)->first()?->status;

        match ($paymentStatus) {
            PaymentStatus::UnderReview => $refuse("We're already reviewing your proof of payment. Please contact the organisers to apply your invitation code."),
            PaymentStatus::Approved => $refuse('You already have a confirmed ticket. If you paid before your invitation arrived, please contact the organisers.'),
            PaymentStatus::Cancelled => $refuse('Your in-person place was cancelled. Please contact the organisers.'),
            default => null,
        };
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['voucher_code' => $message]);
    }
}
