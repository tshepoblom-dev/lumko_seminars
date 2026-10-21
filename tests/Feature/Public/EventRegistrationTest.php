<?php

namespace Tests\Feature\Public;

use App\Enums\ConsentType;
use App\Enums\UserRole;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ConfirmEmailAndSetPassword;
use App\Notifications\ExistingAccountRegistrationAttempt;
use App\Notifications\RegistrationAddedToAccount;
use App\Notifications\RegistrationConfirmed;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Thabo',
            'last_name' => 'Mokoena',
            'email' => 'thabo@example.com',
            'mobile' => '0821234567',
            'organisation' => 'ABC Municipality',
            'job_title' => 'IT Manager',
            'attendance_mode' => 'in_person',
            'networking_consent' => true,
            'lumko_marketing_consent' => false,
            'accept_legal' => true,
        ], $overrides);
    }

    // T-REG-1 — no auto-login, verification sent, consent not yet effective.
    public function test_new_visitor_registering_is_not_logged_in_and_gets_a_confirm_email(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);

        $response = $this->post(route('event.register.store'), $this->validPayload());

        $response->assertRedirect(route('event.register.confirmation'));
        $this->assertGuest();

        $user = User::where('email', 'thabo@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, ConfirmEmailAndSetPassword::class);

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id, 'user_id' => $user->id, 'attendance_mode' => 'in_person',
        ]);

        // Consent was recorded but is not yet effective (unverified).
        $this->assertFalse(app(ConsentService::class)->isGranted($user, ConsentType::Networking, $event));
        $this->assertDatabaseHas('consent_records', [
            'user_id' => $user->id, 'type' => 'networking', 'action' => 'granted',
        ]);
        $record = ConsentRecord::where('user_id', $user->id)->where('type', 'networking')->first();
        $this->assertNull($record->effective_at);
    }

    // T-REG-2 — uniform HTTP response for a new email vs an existing verified account.
    public function test_response_is_uniform_for_new_and_existing_verified_email(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);
        $existingVerified = User::factory()->create(['email' => 'existing@example.com', 'email_verified_at' => now()]);

        $newResponse = $this->post(route('event.register.store'), $this->validPayload(['email' => 'brandnew@example.com']));
        $existingResponse = $this->post(route('event.register.store'), $this->validPayload(['email' => 'existing@example.com']));

        $newResponse->assertRedirect(route('event.register.confirmation'));
        $existingResponse->assertRedirect(route('event.register.confirmation'));
        $this->assertSame($newResponse->getSession()->get('info'), $existingResponse->getSession()->get('info'));

        // The existing account's registration is saved too (owner decision
        // 2026-10-13; details in ExistingAccountRegistrationTest), and its
        // owner is told — with no setup link, since they have a password.
        $this->assertDatabaseHas('event_registrations', ['event_id' => $event->id, 'user_id' => $existingVerified->id]);
        Notification::assertSentTo($existingVerified, RegistrationAddedToAccount::class);
        Notification::assertNotSentTo($existingVerified, ExistingAccountRegistrationAttempt::class);
        Notification::assertNotSentTo($existingVerified, ConfirmEmailAndSetPassword::class);
        $this->assertGuest();
    }

    // D-Public home — /attend shows a friendly closed page, not a 404,
    // once the published event has ended.
    public function test_attend_page_shows_friendly_closed_state_after_event_ends(): void
    {
        Event::factory()->create([
            'status' => 'published', 'name' => 'The Power of Data',
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(),
        ]);

        $response = $this->get(route('event.register'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/RegistrationClosed')
            ->where('event.name', 'The Power of Data')
            ->where('event.has_ended', true)
        );
    }

    public function test_attend_page_shows_generic_closed_state_with_no_published_event(): void
    {
        $response = $this->get(route('event.register'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/RegistrationClosed')
            ->where('event', null)
        );
    }

    // D-Registration — the stepper's per-step validation endpoint reuses
    // RegistrationRules::stepRules() and persists nothing.
    public function test_validate_step_passes_for_valid_step_one_data(): void
    {
        $response = $this->postJson(route('event.register.validate-step'), [
            'step' => 1,
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'email' => 'jane@example.com', 'mobile' => '+27821234567',
        ]);

        $response->assertOk();
        $response->assertJson(['valid' => true]);
        $this->assertSame(0, User::count());
    }

    public function test_validate_step_reports_errors_for_invalid_step_one_data(): void
    {
        $response = $this->postJson(route('event.register.validate-step'), [
            'step' => 1,
            'first_name' => '', 'last_name' => 'Doe',
            'email' => 'not-an-email', 'mobile' => 'x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['first_name', 'email', 'mobile']);
    }

    public function test_validate_step_ignores_fields_outside_the_requested_step(): void
    {
        $response = $this->postJson(route('event.register.validate-step'), [
            'step' => 3,
            'attendance_mode' => 'online',
            'email' => 'not-an-email', // step 1 field, irrelevant to step 3
        ]);

        $response->assertOk();
    }

    // Regression: the stepper's validate route once shared the 3/hour-per-
    // email submit limiter, so an honest visitor was rate-limited on the 4th
    // "Next" click. A full walk through all 7 steps with one email must work.
    public function test_a_full_seven_step_walk_with_one_email_is_never_rate_limited(): void
    {
        $data = [
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'walk@example.com', 'mobile' => '+27821234567',
            'organisation' => 'Acme', 'job_title' => 'CTO', 'attendance_mode' => 'online',
            'answers' => [],
            'networking_consent' => false, 'lumko_marketing_consent' => false,
        ];

        for ($step = 1; $step <= 7; $step++) {
            $this->postJson(route('event.register.validate-step'), ['step' => $step, ...$data])->assertOk();
        }
    }

    public function test_validate_step_rejects_an_out_of_range_step(): void
    {
        $response = $this->postJson(route('event.register.validate-step'), ['step' => 8]);

        $response->assertStatus(422);
    }

    // T-REG-3 — cannot register for a draft/unpublished event (none open at all here).
    public function test_registration_is_rejected_when_no_event_is_open(): void
    {
        Event::factory()->create(['status' => 'draft']);

        $response = $this->post(route('event.register.store'), $this->validPayload());

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'thabo@example.com']);
    }

    // T-REG-4 — option allow-lists, array bounds, mobile format.
    public function test_invalid_interest_option_is_rejected(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $interests = $this->interestsQuestion($event);

        $response = $this->post(route('event.register.store'), $this->validPayload(['answers' => [$interests->id => ['not-a-real-option']]]));

        $response->assertSessionHasErrors("answers.{$interests->id}.0");
    }

    public function test_chosen_interests_are_stored_as_answers_and_in_the_interests_column(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);
        $interests = $this->interestsQuestion($event);
        $ai = collect($interests->options)->firstWhere('label', 'AI')['id'];
        $automation = collect($interests->options)->firstWhere('label', 'Automation')['id'];

        $this->post(route('event.register.store'), $this->validPayload(['answers' => [$interests->id => [$ai, $automation]]]))
            ->assertSessionHasNoErrors();

        $registration = \App\Models\EventRegistration::sole();
        $this->assertSame(['AI', 'Automation'], $registration->interests);
        $answer = $registration->answers()->sole();
        $this->assertSame([$ai, $automation], $answer->value);
        $this->assertSame('AI; Automation', $answer->display);
    }

    private function interestsQuestion(Event $event): \App\Models\RegistrationQuestion
    {
        app(\App\Services\RegistrationForm::class)->ensureDefaults($event);

        return $event->registrationQuestions()->where('key', 'interests')->sole();
    }

    public function test_industry_and_organisation_size_are_saved_when_given(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);

        $this->post(route('event.register.store'), $this->validPayload([
            'industry' => 'Healthcare',
            'organisation_size' => '51-200',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'industry' => 'Healthcare',
            'organisation_size' => '51-200',
        ]);
    }

    public function test_industry_and_organisation_size_are_optional(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);

        $this->post(route('event.register.store'), $this->validPayload(['industry' => '', 'organisation_size' => '']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('event_registrations', ['event_id' => $event->id, 'industry' => null, 'organisation_size' => null]);
    }

    public function test_industry_and_size_must_come_from_the_option_lists(): void
    {
        Event::factory()->create(['status' => 'published']);

        $this->post(route('event.register.store'), $this->validPayload(['industry' => 'Space piracy', 'organisation_size' => 'lots']))
            ->assertSessionHasErrors(['industry', 'organisation_size']);

        $this->postJson(route('event.register.validate-step'), [
            'step' => 2, 'organisation' => 'Acme', 'job_title' => 'Analyst', 'industry' => 'Space piracy',
        ])->assertStatus(422)->assertJsonValidationErrors('industry');
    }

    public function test_malformed_mobile_number_is_rejected(): void
    {
        Event::factory()->create(['status' => 'published']);

        $response = $this->post(route('event.register.store'), $this->validPayload(['mobile' => 'not-a-number']));

        $response->assertSessionHasErrors('mobile');
    }

    public function test_mobile_is_optional(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);

        $this->post(route('event.register.store'), $this->validPayload(['mobile' => '']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('event.register.confirmation'));

        $user = User::where('email', 'thabo@example.com')->first();
        $this->assertNull($user->mobile);
        $this->assertTrue($user->registrations()->where('event_id', $event->id)->exists());

        $this->postJson(route('event.register.validate-step'), [
            'step' => 1, 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'mobile' => '',
        ])->assertOk();
    }

    // Regression: /attend locked the whole step-1 fieldset when signed in,
    // so an account with no mobile on file (e.g. made at /register) could
    // neither type one nor get past the "mobile is required" error.
    public function test_signed_in_user_without_a_mobile_can_register(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee, 'email_verified_at' => now(), 'mobile' => null]);

        $this->actingAs($user)->post(route('event.register.store'), $this->validPayload([
            'email' => $user->email, 'mobile' => '', 'attendance_mode' => 'online',
        ]))->assertRedirect(route('event.register.confirmation'));

        $this->assertTrue($user->registrations()->where('event_id', $event->id)->exists());
        $this->assertNull($user->fresh()->mobile);
    }

    public function test_signed_in_user_can_add_a_mobile_while_registering(): void
    {
        Notification::fake();
        Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee, 'email_verified_at' => now(), 'mobile' => null]);

        $this->actingAs($user)->post(route('event.register.store'), $this->validPayload([
            'email' => $user->email, 'mobile' => '+27 82 123 4567', 'attendance_mode' => 'online',
        ]))->assertRedirect(route('event.register.confirmation'));

        $this->assertSame('+27 82 123 4567', $user->fresh()->mobile);
    }

    public function test_a_blank_mobile_never_erases_one_already_on_file(): void
    {
        Notification::fake();
        Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee, 'email_verified_at' => now(), 'mobile' => '0821234567']);

        $this->actingAs($user)->post(route('event.register.store'), $this->validPayload([
            'email' => $user->email, 'mobile' => '', 'attendance_mode' => 'online',
        ]))->assertRedirect(route('event.register.confirmation'));

        $this->assertSame('0821234567', $user->fresh()->mobile);
    }

    // T-REG-5 — idempotent double submit for the same not-yet-verified email.
    public function test_double_submit_for_same_unverified_email_does_not_duplicate_registration(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);

        $this->post(route('event.register.store'), $this->validPayload());
        $this->post(route('event.register.store'), $this->validPayload());

        $user = User::where('email', 'thabo@example.com')->first();
        $this->assertSame(1, EventRegistration::where('event_id', $event->id)->where('user_id', $user->id)->count());
        $this->assertSame(1, Payment::count());
        Notification::assertSentToTimes($user, ConfirmEmailAndSetPassword::class, 2);
    }

    public function test_authenticated_user_registering_again_for_same_event_is_routed_to_account_page(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($user)->post(route('event.register.store'), $this->validPayload([
            'email' => $user->email, 'attendance_mode' => 'online',
        ]));

        $response->assertRedirect(route('attendee.preferences.edit'));
        $this->assertSame(1, $user->registrations()->where('event_id', $event->id)->count());
    }

    public function test_authenticated_user_can_register_for_a_new_event_and_is_notified(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee, 'email_verified_at' => now()]);

        $response = $this->actingAs($user)->post(route('event.register.store'), $this->validPayload([
            'email' => $user->email, 'attendance_mode' => 'online',
        ]));

        $response->assertRedirect(route('event.register.confirmation'));
        Notification::assertSentTo($user, RegistrationConfirmed::class);
        $this->assertTrue($user->fresh()->registrations()->where('event_id', $event->id)->exists());
    }

    public function test_authenticated_user_cannot_register_a_different_email(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($user)->post(route('event.register.store'), $this->validPayload([
            'email' => 'someone-else@example.com',
        ]));

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('event_registrations', ['event_id' => $event->id]);
    }

    // ConfirmRegistrationController — the other half of the flow.
    public function test_confirm_registration_link_verifies_email_sets_password_and_logs_in(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['email' => 'thabo@example.com', 'email_verified_at' => null]);
        $registration = $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        app(ConsentService::class)->grant($user, ConsentType::Networking, $event, source: 'registration', effectiveImmediately: false);

        $token = Password::broker()->createToken($user);

        $response = $this->post(route('registration.confirm.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue(app(ConsentService::class)->isGranted($user->fresh(), ConsentType::Networking, $event));
    }
}
