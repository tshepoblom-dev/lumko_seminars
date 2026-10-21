<?php

namespace Tests\Feature\Public;

use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\RegistrationConfirmed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * An event can be in person only ("Offer online attendance" off, owner
 * decision 2026-10-21): registration refuses online, the public pages
 * don't offer it, and people already registered online can switch.
 */
class InPersonOnlyTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function inPersonOnly(array $attributes = []): Event
    {
        return $this->makeEvent(['offers_online' => false, 'in_person_price' => 200, 'venue_name' => 'St Eve\'s', ...$attributes]);
    }

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

    public function test_events_offer_online_until_staff_turn_it_off(): void
    {
        $event = $this->makeEvent();
        $this->assertTrue($event->offersOnline());
        $this->assertTrue($event->fresh()->offers_online);

        $admin = $this->makeSuperAdmin();
        $fields = [
            'name' => $event->name, 'slug' => $event->slug, 'status' => 'published',
            'starts_at' => $event->starts_at->format('Y-m-d H:i'), 'ends_at' => $event->ends_at->format('Y-m-d H:i'),
            'online_price' => 0, 'in_person_price' => 200,
        ];

        $this->actingAs($admin)->put(route('admin.events.update', $event), [...$fields, 'offers_online' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($event->fresh()->offersOnline());

        // Leaving the box out of a request changes nothing.
        $this->actingAs($admin)->put(route('admin.events.update', $event), $fields)->assertSessionHasNoErrors();
        $this->assertFalse($event->fresh()->offersOnline());

        $this->actingAs($admin)->put(route('admin.events.update', $event), [...$fields, 'offers_online' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($event->fresh()->offersOnline());
    }

    public function test_registration_refuses_online_when_the_event_is_in_person_only(): void
    {
        Notification::fake();
        $this->inPersonOnly();

        $this->postJson(route('event.register.validate-step'), ['step' => 3, 'attendance_mode' => 'online'])
            ->assertStatus(422)
            ->assertJsonPath('errors.attendance_mode.0', 'This event is in person only.');
        $this->postJson(route('event.register.validate-step'), ['step' => 3, 'attendance_mode' => 'in_person'])
            ->assertOk();

        $this->post(route('event.register.store'), $this->payload(['attendance_mode' => 'online']))
            ->assertSessionHasErrors(['attendance_mode' => 'This event is in person only.']);
        $this->assertSame(0, EventRegistration::count());

        $this->post(route('event.register.store'), $this->payload())
            ->assertRedirect(route('event.register.confirmation'));
        $registration = EventRegistration::sole();
        $this->assertSame('in_person', $registration->attendance_mode->value);
        $this->assertSame('200.00', (string) $registration->payment->amount);
    }

    public function test_a_signed_in_user_cannot_register_online_either(): void
    {
        $this->inPersonOnly();
        $user = User::factory()->create(['email' => 'thabo@example.com']);

        $this->actingAs($user)->post(route('event.register.store'), $this->payload(['attendance_mode' => 'online']))
            ->assertSessionHasErrors('attendance_mode');
        $this->assertSame(0, EventRegistration::count());
    }

    public function test_online_is_still_accepted_while_the_event_offers_it(): void
    {
        Notification::fake();
        $this->makeEvent();

        $this->postJson(route('event.register.validate-step'), ['step' => 3, 'attendance_mode' => 'online'])->assertOk();
        $this->post(route('event.register.store'), $this->payload(['attendance_mode' => 'online']))
            ->assertRedirect(route('event.register.confirmation'));
        $this->assertSame('online', EventRegistration::sole()->attendance_mode->value);
    }

    public function test_the_public_pages_know_whether_online_is_offered(): void
    {
        $this->inPersonOnly();

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('event.offers_online', false));
        $this->get(route('event.register'))->assertInertia(fn (Assert $page) => $page->where('event.offers_online', false));
    }

    public function test_an_online_registrant_is_offered_the_switch_and_an_in_person_one_is_not(): void
    {
        $event = $this->inPersonOnly();
        $online = $this->registerAttendee($event, 'online')->user;
        $inPerson = $this->registerAttendee($event, 'in_person')->user;

        $this->actingAs($online)->get(route('attendee.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('registration.can_switch_to_in_person', true)
            ->where('event.offers_online', false)
            ->where('event.in_person_price', 200));

        $this->actingAs($inPerson)->get(route('attendee.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('registration.can_switch_to_in_person', false));

        // While online is offered, nobody is nudged to switch.
        $event->update(['offers_online' => true]);
        $this->actingAs($online)->get(route('attendee.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('registration.can_switch_to_in_person', false));
    }

    public function test_switching_to_in_person_starts_a_pending_place_and_goes_to_payment(): void
    {
        Notification::fake();
        $event = $this->inPersonOnly();
        $registration = $this->registerAttendee($event, 'online');
        $user = $registration->user;

        $this->actingAs($user)->post(route('registration.switch-to-in-person'))
            ->assertRedirect(route('payment.show'))
            ->assertSessionHas('success');

        $registration->refresh();
        $this->assertSame('in_person', $registration->attendance_mode->value);
        $this->assertSame(PaymentStatus::Pending, $registration->payment->status);
        $this->assertSame('200.00', (string) $registration->payment->amount);
        $this->assertSame(TicketStatus::Pending, $registration->ticket->status);
        $this->assertDatabaseHas('payment_status_histories', ['payment_id' => $registration->payment->id, 'note' => 'Switched from online to in person.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration.switched_to_in_person']);
        Notification::assertSentTo($user, RegistrationConfirmed::class);

        // A second click changes nothing.
        $this->actingAs($user)->post(route('registration.switch-to-in-person'))
            ->assertRedirect(route('payment.show'))
            ->assertSessionHas('info');
        $this->assertSame(1, Payment::where('event_registration_id', $registration->id)->count());
    }

    public function test_switching_is_refused_after_the_event_and_for_guests(): void
    {
        $event = $this->inPersonOnly(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHours(2)]);
        $registration = $this->registerAttendee($event, 'online');

        $this->actingAs($registration->user)->post(route('registration.switch-to-in-person'))
            ->assertRedirect(route('attendee.dashboard'))
            ->assertSessionHas('info');
        $this->assertSame('online', $registration->fresh()->attendance_mode->value);
        $this->assertSame(0, Payment::count());

        auth()->logout();
        $this->post(route('registration.switch-to-in-person'))->assertRedirect(route('login'));
    }
}
