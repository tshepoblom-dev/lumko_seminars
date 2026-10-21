<?php

namespace Tests\Feature\Public;

use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\ConfirmEmailAndSetPassword;
use App\Notifications\PaymentApproved;
use App\Notifications\TicketConfirmed;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * A guest who redeems an invitation code has a confirmed ticket at once,
 * but gets its PDF only when they first prove their address — by the
 * registration link or by "forgot password" — and only once.
 */
class GuestComplimentaryTicketEmailTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private const PASSWORD = 'A-str0ng-passphrase!';

    private function registerGuestWithCode(): User
    {
        $event = $this->makeEvent();
        $voucher = Voucher::factory()->create(['event_id' => $event->id]);

        $this->post(route('event.register.store'), [
            'first_name' => 'Guest', 'last_name' => 'Invitee', 'email' => 'guest@example.com',
            'organisation' => 'Guest Co', 'job_title' => 'Director', 'attendance_mode' => 'in_person',
            'voucher_code' => $voucher->code, 'networking_consent' => false, 'lumko_marketing_consent' => false,
            'accept_legal' => true,
        ])->assertSessionHasNoErrors();

        return User::where('email', 'guest@example.com')->firstOrFail();
    }

    private function setupToken(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, ConfirmEmailAndSetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    public function test_confirming_by_the_registration_link_sends_the_pdf_once(): void
    {
        Notification::fake();
        $user = $this->registerGuestWithCode();

        // Confirmed already, but not emailed: the address isn't proven yet.
        Notification::assertNotSentTo($user, TicketConfirmed::class);

        $this->post(route('registration.confirm.store'), [
            'token' => $this->setupToken($user), 'email' => 'guest@example.com',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        Notification::assertSentToTimes($user, TicketConfirmed::class, 1);

        // A later password reset on the now-verified account sends nothing more.
        $this->post(route('logout'));
        $this->post(route('password.store'), [
            'token' => Password::broker()->createToken($user->fresh()), 'email' => 'guest@example.com',
            'password' => self::PASSWORD.'2', 'password_confirmation' => self::PASSWORD.'2',
        ])->assertSessionHasNoErrors();

        Notification::assertSentToTimes($user, TicketConfirmed::class, 1);
    }

    public function test_confirming_by_forgot_password_sends_it_too(): void
    {
        Notification::fake();
        $user = $this->registerGuestWithCode();

        $this->post(route('password.store'), [
            'token' => Password::broker()->createToken($user), 'email' => 'guest@example.com',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        Notification::assertSentToTimes($user, TicketConfirmed::class, 1);
    }

    public function test_a_paid_ticket_approved_before_confirmation_is_not_sent_twice(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event, 'in_person', ['email' => 'paid@example.com', 'email_verified_at' => null]);
        $payment = Payment::factory()->create(['event_registration_id' => $registration->id]);
        Ticket::factory()->create(['event_registration_id' => $registration->id]);
        app(PaymentService::class)->approve($payment, 'Paid in cash at the office.', manualOverride: true, actor: $this->makeSuperAdmin());

        $this->post(route('password.store'), [
            'token' => Password::broker()->createToken($registration->user), 'email' => 'paid@example.com',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($registration->user, PaymentApproved::class);
        Notification::assertNotSentTo($registration->user, TicketConfirmed::class);
    }
}
