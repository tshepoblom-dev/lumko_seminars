<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EngagementSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_engagement_summary_reflects_registrations_and_feedback(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();

        $online = User::factory()->create(['role' => UserRole::Attendee]);
        $online->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $inPerson = User::factory()->create(['role' => UserRole::Attendee]);
        $inPerson->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered']);

        // Ratings deliberately avoid a whole-number average (e.g. 4 & 2 -> 3.0):
        // PHP's json_encode() drops the trailing .0 from a whole-number float
        // (no JSON_PRESERVE_ZERO_FRACTION here), so Inertia would serialise
        // 3.0 as the JSON number `3`, which decodes back as an int and fails
        // a strict `===` comparison against the float 3.0 — a JSON-encoding
        // quirk, not a bug in the controller's rounding.
        Feedback::create(['event_id' => $event->id, 'user_id' => $online->id, 'rating' => 4]);
        Feedback::create(['event_id' => $event->id, 'user_id' => $inPerson->id, 'rating' => 3]);

        $response = $this->actingAs($admin)->get(route('admin.engagement.show', $event->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Engagement/Show')
            ->where('stats.registered_total', 2)
            ->where('stats.registered_online', 1)
            ->where('stats.registered_in_person', 1)
            ->where('stats.feedback_count', 2)
            ->where('stats.feedback_average_rating', 3.5)
        );
    }

    public function test_attendee_cannot_view_engagement_summary(): void
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $event = Event::factory()->create();

        $response = $this->actingAs($attendee)->get(route('admin.engagement.show', $event->id));

        $response->assertForbidden();
    }

    public function test_unassigned_event_manager_cannot_view_engagement_summary(): void
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event = Event::factory()->create();

        $response = $this->actingAs($manager)->get(route('admin.engagement.show', $event->id));

        $response->assertForbidden();
    }

    public function test_assigned_event_manager_can_view_engagement_summary(): void
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event = Event::factory()->create();
        $event->managers()->attach($manager->id, ['role' => 'event_manager']);

        $response = $this->actingAs($manager)->get(route('admin.engagement.show', $event->id));

        $response->assertOk();
    }
}
