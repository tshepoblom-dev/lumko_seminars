<?php

namespace Tests\Feature\Public;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\Payment;
use App\Models\ProgrammeSession;
use App\Models\Resource;
use App\Models\Speaker;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendeeDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_registration_and_published_announcements(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered',
        ]);

        Announcement::create([
            'event_id' => $event->id, 'title' => 'Programme confirmed', 'body' => 'See you there.',
            'type' => 'event_update', 'published_at' => now()->subHour(),
        ]);
        Announcement::create([
            'event_id' => $event->id, 'title' => 'Draft note', 'body' => 'Not yet visible.',
            'type' => 'event_update', 'published_at' => null,
        ]);

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Dashboard')
            ->has('announcements', 1)
            ->where('announcements.0.title', 'Programme confirmed')
        );
    }

    public function test_dashboard_shows_no_registration_state(): void
    {
        $user = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('Public/DashboardNoRegistration'));
    }

    public function test_online_attendee_journey_has_no_payment_or_ticket_steps(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(2)]);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Dashboard')
            ->where('journey.steps', fn ($steps) => collect($steps)->pluck('key')->doesntContain('payment')
                && collect($steps)->pluck('key')->doesntContain('ticket_confirmed'))
        );
    }

    public function test_in_person_attendee_next_step_is_payment_until_approved(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(2)]);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered']);
        Payment::create(['event_registration_id' => $registration->id, 'amount' => 150, 'status' => 'pending']);

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('journey.nextStep.key', 'payment')
        );
    }

    public function test_in_person_attendee_journey_advances_once_payment_approved_and_ticket_confirmed(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(2)]);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered']);
        Payment::create(['event_registration_id' => $registration->id, 'amount' => 150, 'status' => 'approved']);
        Ticket::create(['event_registration_id' => $registration->id, 'code' => 'ABC123', 'status' => 'confirmed', 'issued_at' => now()]);

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('journey.steps', fn ($steps) => collect($steps)
                ->firstWhere('key', 'payment')['status'] === 'complete'
                && collect($steps)->firstWhere('key', 'ticket_confirmed')['status'] === 'complete')
        );
    }

    public function test_join_live_step_is_current_within_live_lead_window(): void
    {
        $event = Event::factory()->create([
            'status' => 'published', 'is_live' => false,
            'starts_at' => now()->addMinutes(10), 'ends_at' => now()->addHours(2), 'live_lead_minutes' => 15,
        ]);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('journey.nextStep.key', 'join_live')
            ->where('event.within_live_window', true)
        );
    }

    public function test_feedback_step_only_available_after_event_ends_within_window(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('journey.steps', fn ($steps) => collect($steps)->firstWhere('key', 'feedback')['status'] === 'current')
            ->where('event.has_ended', true)
        );
    }

    public function test_programme_and_speakers_are_passed_to_the_view(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $speaker = Speaker::factory()->create(['name' => 'Dr Herkulaas Combrink', 'photo_approved' => false]);
        $event->speakers()->attach($speaker);
        ProgrammeSession::factory()->create([
            'event_id' => $event->id, 'title' => 'Keynote', 'description' => 'Why data sovereignty matters.',
            'start_time' => '2026-11-13 08:30:00', 'end_time' => '2026-11-13 08:55:00',
        ])->speakers()->attach($speaker);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('programme', 1)
            ->where('programme.0.title', 'Keynote')
            // The dashboard is now the only place the programme appears,
            // so it carries the session descriptions the home page used to.
            ->where('programme.0.description', 'Why data sovereignty matters.')
            ->has('speakers', 1)
            ->where('speakers.0.name', 'Dr Herkulaas Combrink')
            ->where('speakers.0.photo_url', null)
        );
    }

    public function test_resources_available_count_reflects_published_and_released_resources(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        Resource::factory()->create(['event_id' => $event->id, 'is_published' => true, 'release_mode' => 'immediate', 'external_url' => 'https://example.com']);
        Resource::factory()->create(['event_id' => $event->id, 'is_published' => false, 'release_mode' => 'immediate', 'external_url' => 'https://example.com/hidden']);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page->where('resourcesAvailableCount', 1));
    }
}
