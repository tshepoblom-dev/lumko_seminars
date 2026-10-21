<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use App\Support\Legal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Privacy Policy + Terms: public pages, required acceptance, and the gate for existing accounts. */
class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function openEvent(): Event
    {
        return Event::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
        ]);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Thabo',
            'last_name' => 'Mokoena',
            'email' => 'thabo@example.com',
            'mobile' => '0821234567',
            'organisation' => 'ABC Municipality',
            'job_title' => 'IT Manager',
            'attendance_mode' => 'online',
            'networking_consent' => false,
            'lumko_marketing_consent' => false,
            'accept_legal' => true,
        ], $overrides);
    }

    public function test_both_documents_are_public_and_flag_missing_facts_as_a_draft(): void
    {
        config(['legal.organiser.information_officer_name' => '[TO COMPLETE]']);

        $this->get(route('legal.privacy'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Legal/Privacy')
                ->where('facts.is_draft', true)
                ->where('facts.information_officer_name', '[TO COMPLETE]')
                ->where('facts.versions.privacy', Legal::version('privacy')));

        $this->get(route('legal.terms'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Public/Legal/Terms'));
    }

    public function test_registration_requires_acceptance_and_records_it_for_a_new_account(): void
    {
        Notification::fake();
        $this->openEvent();

        $this->post(route('event.register.store'), $this->registrationPayload(['accept_legal' => false]))
            ->assertSessionHasErrors('accept_legal');
        $this->assertDatabaseMissing('users', ['email' => 'thabo@example.com']);

        $this->post(route('event.register.store'), $this->registrationPayload())->assertRedirect();

        $user = User::where('email', 'thabo@example.com')->firstOrFail();
        $this->assertTrue(Legal::hasAcceptedCurrent($user));
        $this->assertDatabaseHas('legal_acceptances', ['user_id' => $user->id, 'document' => 'privacy', 'source' => 'registration']);
    }

    public function test_an_anonymous_submission_is_not_recorded_against_an_existing_verified_account(): void
    {
        Notification::fake();
        $this->openEvent();
        $owner = User::factory()->withoutLegalAcceptance()->create(['email' => 'owner@example.com', 'role' => UserRole::Attendee]);

        $this->post(route('event.register.store'), $this->registrationPayload(['email' => 'owner@example.com']));

        $this->assertFalse(Legal::hasAcceptedCurrent($owner));
    }

    public function test_existing_accounts_are_asked_once_then_returned_to_where_they_were_going(): void
    {
        $user = User::factory()->withoutLegalAcceptance()->create(['role' => UserRole::Attendee]);

        $this->actingAs($user)->get(route('attendee.dashboard'))->assertRedirect(route('legal.accept'));

        $this->actingAs($user)->get(route('legal.accept'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Public/Legal/Accept')->where('updated', false));

        $this->actingAs($user)->post(route('legal.accept.store'), [])->assertSessionHasErrors('accept_legal');

        $this->actingAs($user)->post(route('legal.accept.store'), ['accept_legal' => '1'])
            ->assertRedirect(route('attendee.dashboard'));
        $this->assertDatabaseHas('legal_acceptances', ['user_id' => $user->id, 'document' => 'terms', 'source' => 'prompt']);

        $this->actingAs($user)->get(route('attendee.dashboard'))->assertOk();
    }

    public function test_a_new_version_asks_everyone_again(): void
    {
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $this->actingAs($user)->get(route('attendee.dashboard'))->assertOk();

        config(['legal.versions.terms' => '2027-01-01']);

        $this->actingAs($user)->get(route('attendee.dashboard'))->assertRedirect(route('legal.accept'));
        $this->actingAs($user)->get(route('legal.accept'))
            ->assertInertia(fn (Assert $page) => $page->where('updated', true));
    }

    public function test_staff_are_asked_too(): void
    {
        $admin = User::factory()->withoutLegalAcceptance()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('legal.accept'));
    }

    public function test_leaving_stays_possible_without_accepting(): void
    {
        $user = User::factory()->withoutLegalAcceptance()->create(['role' => UserRole::Attendee]);

        $this->actingAs($user)->get(route('attendee.preferences.edit'))->assertOk();
        $this->actingAs($user)->get(route('attendee.preferences.download'))->assertOk();
        $this->actingAs($user)->get(route('legal.privacy'))->assertOk();
    }

    public function test_json_callers_get_a_clear_error_instead_of_a_redirect(): void
    {
        $event = $this->openEvent();
        $user = User::factory()->withoutLegalAcceptance()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $this->actingAs($user)->getJson(route('live.agora-token', $event))->assertStatus(409);
    }
}
