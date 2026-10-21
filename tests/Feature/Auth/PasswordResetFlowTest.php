<?php

namespace Tests\Feature\Auth;

use App\Enums\ConsentType;
use App\Models\Event;
use App\Models\User;
use App\Notifications\ConfirmEmailAndSetPassword;
use App\Services\ConsentService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * "Forgot password" and the registration set-password email, for the people
 * who most need them: registrants who never used their setup link.
 */
class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registrant_who_never_confirmed_can_get_in_through_forgot_password(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(2)]);

        $this->post(route('event.register.store'), [
            'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'email' => 'thabo@example.com', 'mobile' => '0821234567',
            'organisation' => 'ABC', 'job_title' => 'CIO', 'attendance_mode' => 'online',
            'networking_consent' => true, 'lumko_marketing_consent' => false, 'accept_legal' => true,
        ]);
        $user = User::where('email', 'thabo@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());

        // They ignore the setup email and use "forgot password" instead (a
        // little later: the broker allows one token a minute, and
        // registration has just issued one).
        $this->travel(2)->minutes();
        $this->post(route('password.email'), ['email' => 'thabo@example.com'])->assertSessionHas('status');
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'thabo@example.com',
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
        ])->assertRedirect(route('login'));

        $user->refresh();
        // The reset link proved they own the inbox, as the setup link would have.
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(app(ConsentService::class)->isGranted($user, ConsentType::Networking, $event));

        $this->post(route('login'), ['email' => 'thabo@example.com', 'password' => 'a-strong-password-1']);
        $this->get(route('attendee.dashboard'))->assertOk();
    }

    public function test_forgot_password_gives_the_same_answer_for_unknown_addresses(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->post(route('password.email'), ['email' => 'known@example.com']);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
        $this->assertSame(session('status'), $known->getSession()->get('status'));
        $this->assertStringContainsString('If an account exists', (string) session('status'));
    }

    public function test_a_mail_server_failure_is_a_retry_message_not_a_500(): void
    {
        User::factory()->create(['email' => 'known@example.com']);
        Password::shouldReceive('sendResetLink')->andThrow(new TransportException('Connection refused'));

        $this->post(route('password.email'), ['email' => 'known@example.com'])
            ->assertSessionHasErrors(['email' => 'We couldn\'t send the email just now. Please try again in a few minutes.']);
    }

    public function test_the_setup_email_links_to_the_confirm_page_on_app_url_and_to_the_legal_pages(): void
    {
        config(['app.url' => 'https://seminar.example.test']);
        url()->forceRootUrl('https://seminar.example.test');
        url()->forceScheme('https');
        $event = Event::factory()->create(['name' => 'The Power of Data']);
        $user = User::factory()->unverified()->create(['first_name' => 'Thabo']);

        $mail = (new ConfirmEmailAndSetPassword($event, 'the-token'))->toMail($user);

        $this->assertStringStartsWith('https://seminar.example.test/confirm-registration/the-token', $mail->actionUrl);
        $body = implode("\n", $mail->outroLines);
        $this->assertStringContainsString('https://seminar.example.test/privacy', $body);
        $this->assertStringContainsString('https://seminar.example.test/terms', $body);
    }
}
