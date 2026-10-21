<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Notifications\ConfirmEmailAndSetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * Registrants get a "confirm your email and set a password" link; staff
 * can re-send it to anyone who hasn't set their account up yet.
 */
class AccountSetupLinkTest extends TestCase
{
    use CreatesEventWorld;
    use RefreshDatabase;

    public function test_admin_can_resend_the_setup_link_to_one_registrant(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event, userAttributes: ['email_verified_at' => null]);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.attendees.setup-link', [$event, $registration]))
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($registration->user, ConfirmEmailAndSetPassword::class, function ($notification) use ($event) {
            return $notification->event->is($event);
        });
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $registration->user->email]);
        $this->assertTrue(AuditLog::where('action', 'attendee.setup_link_sent')->where('event_id', $event->id)->exists());
    }

    public function test_the_emailed_link_lets_the_registrant_set_a_password(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event, userAttributes: ['email_verified_at' => null]);

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.attendees.setup-link', [$event, $registration]));
        auth()->logout();

        $token = null;
        Notification::assertSentTo($registration->user, ConfirmEmailAndSetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post(route('registration.confirm.store'), [
            'token' => $token,
            'email' => $registration->user->email,
            'password' => 'a-Strong-passw0rd!',
            'password_confirmation' => 'a-Strong-passw0rd!',
        ])->assertRedirect();

        $this->assertNotNull($registration->user->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($registration->user->fresh());
    }

    public function test_an_account_that_is_already_set_up_is_left_alone(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event); // factory users are verified

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.attendees.setup-link', [$event, $registration]))
            ->assertSessionHas('info');

        Notification::assertNothingSent();
    }

    public function test_bulk_send_reaches_only_registrants_without_an_account(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $pendingA = $this->registerAttendee($event, userAttributes: ['email_verified_at' => null]);
        $pendingB = $this->registerAttendee($event, userAttributes: ['email_verified_at' => null]);
        $setUp = $this->registerAttendee($event);
        $anonymised = $this->registerAttendee($event, userAttributes: ['email_verified_at' => null, 'email' => 'deleted-abc@invalid']);
        $otherEvent = $this->makeEvent();
        $elsewhere = $this->registerAttendee($otherEvent, userAttributes: ['email_verified_at' => null]);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.attendees.setup-links', $event))
            ->assertSessionHas('success', 'Setup links sent to 2 registrant(s).');

        Notification::assertSentTo([$pendingA->user, $pendingB->user], ConfirmEmailAndSetPassword::class);
        Notification::assertNotSentTo($setUp->user, ConfirmEmailAndSetPassword::class);
        Notification::assertNotSentTo($anonymised->user, ConfirmEmailAndSetPassword::class);
        Notification::assertNotSentTo($elsewhere->user, ConfirmEmailAndSetPassword::class);
        $this->assertSame(['count' => 2], AuditLog::where('action', 'attendees.setup_links_sent')->first()->after);
    }

    public function test_attendee_list_reports_who_has_not_set_up_their_account(): void
    {
        $event = $this->makeEvent();
        $this->registerAttendee($event, userAttributes: ['email_verified_at' => null]);
        $this->registerAttendee($event);

        $this->actingAs($this->makeSuperAdmin())
            ->get(route('admin.attendees.index', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notSetUpCount', 1)
                ->has('attendees.data', 2)
            );
    }

    public function test_a_manager_cannot_send_links_for_another_event(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $otherEvent = $this->makeEvent();
        $manager = $this->makeManagerFor($otherEvent);
        $registration = $this->registerAttendee($event, userAttributes: ['email_verified_at' => null]);

        $this->actingAs($manager)->post(route('admin.attendees.setup-link', [$event, $registration]))->assertForbidden();
        $this->actingAs($manager)->post(route('admin.attendees.setup-links', $event))->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_a_registration_from_another_event_is_not_found(): void
    {
        $event = $this->makeEvent();
        $otherEvent = $this->makeEvent();
        $registration = $this->registerAttendee($otherEvent, userAttributes: ['email_verified_at' => null]);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.attendees.setup-link', [$event, $registration]))
            ->assertNotFound();
    }

    public function test_setup_links_are_rate_limited(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($admin)->post(route('admin.attendees.setup-links', $event))->assertRedirect();
        }

        $this->actingAs($admin)->post(route('admin.attendees.setup-links', $event))->assertStatus(429);
    }
}
