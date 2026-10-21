<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_defaults_to_the_most_recent_event_and_shows_cards(): void
    {
        Cache::flush();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['name' => 'The Power of Data', 'starts_at' => now()->addWeek()]);

        $online = User::factory()->create(['organisation' => 'Acme Co', 'job_title' => 'CTO']);
        $onlineReg = $online->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered',
            'interests' => ['ai', 'data-governance'],
        ]);

        $inPerson = User::factory()->create(['organisation' => 'Acme Co', 'job_title' => 'Analyst']);
        $inPersonReg = $inPerson->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered',
            'interests' => ['ai'],
            'improvement_area' => ['Reporting'],
        ]);
        Payment::create(['event_registration_id' => $inPersonReg->id, 'amount' => 150, 'status' => 'approved']);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard')
            ->where('selectedEventId', $event->id)
            ->where('eventStats.registered_total', 2)
            ->where('eventStats.registered_online', 1)
            ->where('eventStats.registered_in_person', 1)
            ->where('eventStats.payment_totals.approved', 1)
            // 150.0 round-trips through JSON as the int 150 (no
            // JSON_PRESERVE_ZERO_FRACTION) — the same quirk documented in
            // EngagementSummaryTest, not a bug in the controller.
            ->where('eventStats.payment_totals.verified_amount', 150)
            ->where('eventStats.by_organisation.0.label', 'Acme Co')
            ->where('eventStats.by_organisation.0.count', 2)
            ->where('eventStats.by_interest.0.label', 'ai')
            ->where('eventStats.by_interest.0.count', 2)
            ->where('eventStats.by_improvement_area.0.label', 'Reporting')
            ->where('eventStats.by_improvement_area.0.count', 1)
        );
    }

    public function test_dashboard_shows_no_event_stats_when_no_event_exists(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('eventStats', null)
            ->where('selectedEventId', null)
        );
    }

    public function test_event_manager_only_sees_assigned_events_in_the_selector(): void
    {
        Cache::flush();
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $assigned = Event::factory()->create(['starts_at' => now()->addWeek()]);
        $other = Event::factory()->create(['starts_at' => now()->addWeeks(2)]);
        $manager->managedEvents()->attach($assigned->id, ['role' => 'event_manager']);

        $response = $this->actingAs($manager)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('events', 1)
            ->where('events.0.id', $assigned->id)
        );
    }

    public function test_selecting_a_specific_event_via_query_param_switches_the_cards(): void
    {
        Cache::flush();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $eventA = Event::factory()->create(['starts_at' => now()->addWeek()]);
        $eventB = Event::factory()->create(['starts_at' => now()->addWeeks(2)]);
        $attendee = User::factory()->create();
        $attendee->registrations()->create(['event_id' => $eventB->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($admin)->get(route('admin.dashboard', ['event' => $eventB->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('selectedEventId', $eventB->id)
            ->where('eventStats.registered_total', 1)
        );
    }

    // Closes the "please verify" note in the Stage D report: a crafted
    // ?event= id for an event this manager isn't assigned to must not
    // leak that event's cards, since $events is already scoped by
    // Event::visibleTo() before firstWhere() ever looks at the param.
    public function test_event_manager_cannot_select_an_unassigned_events_id_via_query_param(): void
    {
        Cache::flush();
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $assigned = Event::factory()->create(['starts_at' => now()->addWeek()]);
        $unassigned = Event::factory()->create(['starts_at' => now()->addWeeks(2)]);
        $manager->managedEvents()->attach($assigned->id, ['role' => 'event_manager']);
        $attendee = User::factory()->create();
        $attendee->registrations()->create(['event_id' => $unassigned->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($manager)->get(route('admin.dashboard', ['event' => $unassigned->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('eventStats', null)
            ->where('selectedEventId', null)
        );
    }
}
