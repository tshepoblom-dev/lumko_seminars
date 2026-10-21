<?php

namespace Tests\Feature\Public;

use App\Actions\AccountAnonymiser;
use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\ConfirmEmailAndSetPassword;
use App\Notifications\ExistingAccountRegistrationAttempt;
use App\Notifications\RegistrationAddedToAccount;
use App\Notifications\RegistrationConfirmed;
use App\Notifications\TicketConfirmed;
use App\Services\VoucherService;
use App\Support\JourneyBuilder;
use App\Support\VoucherCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * Invitation codes (vouchers): a complimentary in-person ticket, redeemed
 * on the registration form or from an account (/redeem).
 */
class VoucherRedemptionTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Thabo',
            'last_name' => 'Mokoena',
            'email' => 'thabo@example.com',
            'organisation' => 'ABC Municipality',
            'job_title' => 'IT Manager',
            'attendance_mode' => 'in_person',
            'networking_consent' => false,
            'lumko_marketing_consent' => false,
            'accept_legal' => true,
        ], $overrides);
    }

    private function voucherFor(Event $event, array $attributes = []): Voucher
    {
        return Voucher::factory()->create(['event_id' => $event->id, ...$attributes]);
    }

    private function assertComplimentary(EventRegistration $registration, Voucher $voucher): void
    {
        $registration->refresh();

        $this->assertSame($voucher->id, $registration->voucher_id);
        $this->assertSame('in_person', $registration->attendance_mode->value);
        $this->assertSame(PaymentStatus::Approved, $registration->payment->status);
        $this->assertSame('0.00', $registration->payment->amount);
        $this->assertTrue($registration->payment->manual_override);
        $this->assertSame(TicketStatus::Confirmed, $registration->ticket->status);
        $this->assertNotNull($registration->ticket->issued_at);
        $this->assertNotNull($voucher->fresh()->redeemed_at);
    }

    // ── On the registration form ───────────────────────────────────────

    public function test_a_new_guest_with_a_code_gets_a_confirmed_complimentary_ticket(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $voucher = $this->voucherFor($event);

        // Typed in lower case with spaces: still the same code.
        $this->post(route('event.register.store'), $this->payload([
            'email' => 'guest@example.com',
            'voucher_code' => strtolower(str_replace('-', ' ', $voucher->code)),
        ]))->assertRedirect(route('event.register.confirmation'));

        $user = User::where('email', 'guest@example.com')->firstOrFail();
        $this->assertComplimentary($user->registrations()->firstOrFail(), $voucher);
        Notification::assertSentTo($user, ConfirmEmailAndSetPassword::class, fn ($n) => $n->complimentary === true);
    }

    public function test_a_code_sent_with_online_attendance_is_ignored(): void
    {
        $event = $this->makeEvent();
        $voucher = $this->voucherFor($event);

        $this->post(route('event.register.store'), $this->payload([
            'attendance_mode' => 'online',
            'voucher_code' => $voucher->code,
        ]))->assertRedirect(route('event.register.confirmation'));

        $registration = EventRegistration::firstOrFail();
        $this->assertSame('online', $registration->attendance_mode->value);
        $this->assertNull($registration->voucher_id);
        $this->assertNull($voucher->fresh()->redeemed_at);
    }

    public function test_a_signed_in_visitor_registering_with_a_code_reserved_for_them_goes_straight_to_their_ticket(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $user = User::factory()->create(['email' => 'vip@example.com', 'role' => UserRole::Attendee]);
        $voucher = Voucher::factory()->lockedTo('VIP@example.com')->create(['event_id' => $event->id]);

        $this->actingAs($user)
            ->post(route('event.register.store'), $this->payload(['email' => 'vip@example.com', 'voucher_code' => $voucher->code]))
            ->assertRedirect(route('ticket.show'));

        $registration = $user->registrations()->firstOrFail();
        $this->assertComplimentary($registration, $voucher);
        Notification::assertSentTo($user, RegistrationConfirmed::class, function (RegistrationConfirmed $n) use ($user) {
            $mail = $n->toMail($user);

            return $mail->actionUrl === route('ticket.show')
                && str_contains(implode(' ', $mail->introLines), 'complimentary');
        });
    }

    public function test_an_already_registered_signed_in_attendee_can_apply_a_code_on_the_form(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        ['registration' => $registration, 'payment' => $payment] = $this->registerPaidInPersonAttendee($event, approved: false);
        $voucher = $this->voucherFor($event);

        $this->actingAs($registration->user)
            ->post(route('event.register.store'), $this->payload(['email' => $registration->user->email, 'voucher_code' => $voucher->code]))
            ->assertRedirect(route('ticket.show'));

        $this->assertComplimentary($registration, $voucher);
        $this->assertDatabaseHas('payment_status_histories', [
            'payment_id' => $payment->id, 'from_status' => 'pending', 'to_status' => 'approved',
        ]);
        Notification::assertSentTo($registration->user, TicketConfirmed::class);
    }

    public static function badCodes(): array
    {
        return [
            'unknown' => ['unknown', "We don't recognise that invitation code"],
            'for another event' => ['other_event', "We don't recognise that invitation code"],
            'revoked' => ['revoked', 'This invitation code is no longer valid.'],
            'already used' => ['redeemed', 'This invitation code has already been used.'],
            'reserved for someone else' => ['locked', 'This invitation code was issued to a different email address.'],
        ];
    }

    private function badCode(string $case, Event $event): string
    {
        return match ($case) {
            'unknown' => 'ABCD-EFGH-JKMN',
            'other_event' => $this->voucherFor($this->makeEvent(['starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addHour()]))->code,
            'revoked' => Voucher::factory()->revoked()->create(['event_id' => $event->id])->code,
            'redeemed' => Voucher::factory()->redeemed()->create(['event_id' => $event->id])->code,
            'locked' => Voucher::factory()->lockedTo('someone-else@example.com')->create(['event_id' => $event->id])->code,
        };
    }

    #[DataProvider('badCodes')]
    public function test_a_bad_code_is_reported_on_step_3_and_on_submit_and_nothing_is_created(string $case, string $message): void
    {
        $event = $this->makeEvent();
        $code = $this->badCode($case, $event);

        $this->postJson(route('event.register.validate-step'), [
            'step' => 3, 'attendance_mode' => 'in_person', 'voucher_code' => $code, 'email' => 'thabo@example.com',
        ])->assertStatus(422)->assertJsonPath('errors.voucher_code.0', fn ($m) => str_starts_with($m, $message));

        $this->post(route('event.register.store'), $this->payload(['voucher_code' => $code]))
            ->assertSessionHasErrors('voucher_code');

        $this->assertDatabaseMissing('users', ['email' => 'thabo@example.com']);
        $this->assertSame(0, EventRegistration::count());
    }

    public function test_step_3_accepts_a_good_code_without_using_it_up(): void
    {
        $event = $this->makeEvent();
        $voucher = Voucher::factory()->lockedTo('thabo@example.com')->create(['event_id' => $event->id]);

        $this->postJson(route('event.register.validate-step'), [
            'step' => 3, 'attendance_mode' => 'in_person', 'voucher_code' => $voucher->code, 'email' => 'Thabo@Example.com',
        ])->assertOk()->assertJson(['valid' => true]);

        $this->assertNull($voucher->fresh()->redeemed_at);
    }

    // T-REG-2 with a code: still nothing observable about the account.
    public function test_the_guest_response_stays_uniform_and_the_code_is_used_for_a_new_or_an_existing_account(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $existing = User::factory()->create(['email' => 'existing@example.com', 'email_verified_at' => now()]);
        $forNew = $this->voucherFor($event);
        $forExisting = $this->voucherFor($event);

        $new = $this->post(route('event.register.store'), $this->payload(['email' => 'brandnew@example.com', 'voucher_code' => $forNew->code]));
        $old = $this->post(route('event.register.store'), $this->payload(['email' => 'existing@example.com', 'voucher_code' => $forExisting->code]));

        $new->assertRedirect(route('event.register.confirmation'));
        $old->assertRedirect(route('event.register.confirmation'));
        $this->assertSame($new->getSession()->get('info'), $old->getSession()->get('info'));

        // An existing confirmed account that isn't registered yet is
        // registered like anyone else, complimentary ticket included.
        $this->assertNotNull($forNew->fresh()->redeemed_at);
        $this->assertNotNull($forExisting->fresh()->redeemed_at);
        $registration = EventRegistration::where('user_id', $existing->id)->sole();
        $this->assertSame($forExisting->id, $registration->voucher_id);
        $this->assertSame(TicketStatus::Confirmed, $registration->ticket->status);
        Notification::assertSentTo($existing, RegistrationAddedToAccount::class);
    }

    public function test_a_guest_code_for_an_account_that_is_already_registered_stays_unused(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event, 'online', ['email' => 'existing@example.com', 'email_verified_at' => now()]);
        $voucher = $this->voucherFor($event);

        $this->post(route('event.register.store'), $this->payload(['email' => 'existing@example.com', 'voucher_code' => $voucher->code]))
            ->assertRedirect(route('event.register.confirmation'));

        // Their registration is left exactly as it is; they redeem the
        // code themselves after signing in (the email says where).
        $this->assertNull($voucher->fresh()->redeemed_at);
        $this->assertSame('online', $registration->fresh()->attendance_mode->value);
        Notification::assertSentTo($registration->user, ExistingAccountRegistrationAttempt::class, fn ($n) => $n->hadInvitationCode === true);
    }

    public function test_a_guest_code_upgrades_an_earlier_unconfirmed_online_registration(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event, 'online', ['email' => 'pending@example.com', 'email_verified_at' => null]);
        $voucher = $this->voucherFor($event);

        $this->post(route('event.register.store'), $this->payload(['email' => 'pending@example.com', 'voucher_code' => $voucher->code]))
            ->assertRedirect(route('event.register.confirmation'));

        $this->assertComplimentary($registration, $voucher);
    }

    public function test_a_guest_code_that_cannot_apply_to_an_unconfirmed_registration_is_silently_left_unused(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event, 'in_person', ['email' => 'pending@example.com', 'email_verified_at' => null]);
        Payment::factory()->status('under_review')->create(['event_registration_id' => $registration->id]);
        $voucher = $this->voucherFor($event);

        $response = $this->post(route('event.register.store'), $this->payload(['email' => 'pending@example.com', 'voucher_code' => $voucher->code]));

        $response->assertRedirect(route('event.register.confirmation'))->assertSessionHasNoErrors();
        $this->assertSame('Check your inbox to confirm your registration.', $response->getSession()->get('info'));
        $this->assertNull($voucher->fresh()->redeemed_at);
        $this->assertNull($registration->fresh()->voucher_id);
    }

    public function test_a_code_works_once(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $voucher = $this->voucherFor($event);

        $this->post(route('event.register.store'), $this->payload(['email' => 'first@example.com', 'voucher_code' => $voucher->code]))
            ->assertSessionHasNoErrors();
        $this->post(route('event.register.store'), $this->payload(['email' => 'second@example.com', 'voucher_code' => $voucher->code]))
            ->assertSessionHasErrors(['voucher_code' => 'This invitation code has already been used.']);

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
        $this->assertSame(1, EventRegistration::whereNotNull('voucher_id')->count());
    }

    public function test_a_stale_code_loses_the_race_without_touching_the_second_registration(): void
    {
        $event = $this->makeEvent();
        $voucher = $this->voucherFor($event);
        $vouchers = app(VoucherService::class);
        $first = $this->registerAttendee($event);
        $second = $this->registerAttendee($event);

        // Both requests resolved the code while it was still unused.
        $resolvedByFirst = $vouchers->resolve($voucher->code, null, $event);
        $resolvedBySecond = $vouchers->resolve($voucher->code, null, $event);

        $vouchers->redeem($resolvedByFirst, $first, $first->user);

        try {
            $vouchers->redeem($resolvedBySecond, $second, $second->user);
            $this->fail('The second redemption should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['This invitation code has already been used.'], $e->errors()['voucher_code']);
        }

        $second->refresh();
        $this->assertNull($second->voucher_id);
        $this->assertSame('online', $second->attendance_mode->value);
        $this->assertNull($second->payment);
        $this->assertComplimentary($first, $voucher);
    }

    public function test_a_code_revoked_after_it_was_checked_is_refused(): void
    {
        $event = $this->makeEvent();
        $voucher = $this->voucherFor($event);
        $vouchers = app(VoucherService::class);
        $registration = $this->registerAttendee($event);

        $resolved = $vouchers->resolve($voucher->code, null, $event);
        $this->assertTrue($vouchers->revoke($voucher, $this->makeSuperAdmin()));

        $this->expectException(ValidationException::class);
        $vouchers->redeem($resolved, $registration, $registration->user);
    }

    // ── Links never use a code up ──────────────────────────────────────

    public function test_an_invitation_link_only_prefills_the_form(): void
    {
        $event = $this->makeEvent();
        $voucher = $this->voucherFor($event);

        $this->get(route('event.register', ['code' => $voucher->code]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Public/Register')->where('prefillVoucherCode', $voucher->code));

        $this->get(route('event.register', ['code' => '<script>alert(1)</script>']))
            ->assertInertia(fn (Assert $page) => $page->where('prefillVoucherCode', null));

        $this->assertNull($voucher->fresh()->redeemed_at);
    }

    public function test_a_registered_attendee_following_an_invitation_link_is_sent_to_redeem_it(): void
    {
        $event = $this->makeEvent();
        $voucher = $this->voucherFor($event);
        $registration = $this->registerAttendee($event);

        $this->actingAs($registration->user)
            ->get(route('event.register', ['code' => $voucher->code]))
            ->assertRedirect(route('voucher.redeem', ['code' => $voucher->code]));

        $this->actingAs($registration->user)
            ->get(route('voucher.redeem', ['code' => $voucher->code]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Public/RedeemVoucher')->where('code', $voucher->code));

        $this->assertNull($voucher->fresh()->redeemed_at);
    }

    // ── From an account (/redeem) ──────────────────────────────────────

    public function test_an_online_attendee_redeeming_a_code_moves_to_in_person_with_a_confirmed_ticket(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event, 'online');
        $voucher = $this->voucherFor($event);

        $this->actingAs($registration->user)
            ->post(route('voucher.redeem.store'), ['voucher_code' => $voucher->code])
            ->assertRedirect(route('ticket.show'))
            ->assertSessionHas('success');

        $this->assertComplimentary($registration, $voucher);
        $this->assertNotNull($registration->fresh()->ticket->code_hash);
        Notification::assertSentTo($registration->user, TicketConfirmed::class);
    }

    public function test_a_rejected_payment_can_be_replaced_by_a_code(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        ['registration' => $registration, 'payment' => $payment] = $this->registerPaidInPersonAttendee($event, approved: false);
        $payment->update(['status' => PaymentStatus::Rejected, 'admin_note' => 'Wrong reference']);
        $voucher = $this->voucherFor($event);

        $this->actingAs($registration->user)
            ->post(route('voucher.redeem.store'), ['voucher_code' => $voucher->code])
            ->assertRedirect(route('ticket.show'));

        $this->assertComplimentary($registration, $voucher);
    }

    public static function unwaivablePayments(): array
    {
        return [
            'proof under review' => ['under_review', "We're already reviewing your proof of payment. Please contact the organisers to apply your invitation code."],
            'already approved' => ['approved', 'You already have a confirmed ticket. If you paid before your invitation arrived, please contact the organisers.'],
            'cancelled' => ['cancelled', 'Your in-person place was cancelled. Please contact the organisers.'],
        ];
    }

    #[DataProvider('unwaivablePayments')]
    public function test_a_code_is_refused_once_money_may_have_moved_or_the_seat_is_settled(string $status, string $message): void
    {
        $event = $this->makeEvent();
        ['registration' => $registration, 'payment' => $payment] = $this->registerPaidInPersonAttendee($event, approved: false);
        $payment->update(['status' => $status]);
        $voucher = $this->voucherFor($event);

        $this->actingAs($registration->user)
            ->post(route('voucher.redeem.store'), ['voucher_code' => $voucher->code])
            ->assertSessionHasErrors(['voucher_code' => $message]);

        $this->assertNull($voucher->fresh()->redeemed_at);
        $this->assertSame($status, $payment->fresh()->status->value);
        $this->assertSame('150.00', $payment->fresh()->amount);
        $this->assertNull($registration->fresh()->voucher_id);
    }

    public function test_redeeming_without_a_registration_sends_the_code_to_the_registration_form(): void
    {
        $event = $this->makeEvent();
        $voucher = $this->voucherFor($event);
        $user = User::factory()->create(['role' => UserRole::Attendee]);

        $this->actingAs($user)
            ->post(route('voucher.redeem.store'), ['voucher_code' => strtolower($voucher->code)])
            ->assertRedirect(route('event.register', ['code' => $voucher->code]));

        $this->assertNull($voucher->fresh()->redeemed_at);
    }

    public function test_a_code_for_an_event_that_has_ended_no_longer_works(): void
    {
        $event = $this->makeEvent(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $registration = $this->registerAttendee($event);
        $voucher = $this->voucherFor($event);

        $this->actingAs($registration->user)
            ->post(route('voucher.redeem.store'), ['voucher_code' => $voucher->code])
            ->assertSessionHasErrors(['voucher_code' => 'This invitation code is no longer valid.']);
    }

    public function test_redeeming_needs_a_signed_in_verified_account(): void
    {
        $this->post(route('voucher.redeem.store'), ['voucher_code' => 'ABCD-EFGH-JKMN'])->assertRedirect(route('login'));
    }

    // ── What a complimentary attendee sees ─────────────────────────────

    public function test_a_complimentary_attendee_has_a_ticket_and_no_payment_step(): void
    {
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event);
        app(VoucherService::class)->redeem($this->voucherFor($event), $registration, $registration->user);

        $this->actingAs($registration->user)->get(route('ticket.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('confirmed', true)
                ->where('qrSvg', fn ($svg) => is_string($svg) && str_contains($svg, '<svg')));

        $this->actingAs($registration->user)->get(route('payment.show'))->assertRedirect(route('ticket.show'));

        $steps = collect(JourneyBuilder::build($registration->fresh(), now()))->keyBy('key');
        $this->assertFalse($steps->has('payment'));
        $this->assertSame('complete', $steps['ticket_confirmed']['status']);

        $this->actingAs($registration->user)->get(route('attendee.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('registration.complimentary', true));
    }

    // ── Audit and privacy ──────────────────────────────────────────────

    public function test_redemptions_are_audited_without_the_code(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $voucher = $this->voucherFor($event);

        $this->post(route('event.register.store'), $this->payload(['email' => 'guest@example.com', 'voucher_code' => $voucher->code]));

        $guest = User::where('email', 'guest@example.com')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => 'voucher.redeemed', 'subject_id' => $voucher->id, 'actor_id' => $guest->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.waived', 'actor_id' => $guest->id]);

        foreach (AuditLog::all() as $log) {
            $logged = json_encode([$log->before, $log->after]);
            $this->assertStringNotContainsString($voucher->code, $logged);
            $this->assertStringNotContainsString(VoucherCode::normalise($voucher->code), $logged);
        }
    }

    public function test_anonymising_an_account_clears_its_name_and_email_from_invitation_codes(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event, 'online', ['email' => 'vip@example.com']);
        $redeemed = Voucher::factory()->lockedTo('vip@example.com')->create(['event_id' => $event->id, 'note' => 'Guest of the CEO']);
        app(VoucherService::class)->redeem($redeemed, $registration, $registration->user);
        $unused = Voucher::factory()->lockedTo('vip@example.com')->create(['event_id' => $event->id, 'recipient_name' => 'Vusi Invitee']);
        $someoneElse = Voucher::factory()->lockedTo('other@example.com')->create(['event_id' => $event->id, 'recipient_name' => 'Other Person']);

        app(AccountAnonymiser::class)->anonymise($registration->user);

        foreach ([$redeemed, $unused] as $voucher) {
            $voucher->refresh();
            $this->assertSame('Deleted user', $voucher->recipient_name);
            $this->assertNull($voucher->recipient_email);
            $this->assertNull($voucher->note);
        }
        $this->assertSame('Other Person', $someoneElse->fresh()->recipient_name);
        // The complimentary seat itself is kept.
        $this->assertSame($redeemed->id, $registration->fresh()->voucher_id);
    }
}
