<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeedbackIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_details_are_hidden_unless_follow_up_was_requested(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();

        $anonymous = User::factory()->create(['name' => 'Jane Anonymous']);
        Feedback::create(['event_id' => $event->id, 'user_id' => $anonymous->id, 'rating' => 4, 'wants_contact' => false]);

        $wantsContact = User::factory()->create(['name' => 'Sam Followup', 'email' => 'sam@example.com']);
        Feedback::create(['event_id' => $event->id, 'user_id' => $wantsContact->id, 'rating' => 5, 'wants_contact' => true]);

        $response = $this->actingAs($admin)->get(route('admin.feedback.index', $event));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Feedback/Index')
            ->where('summary.wants_contact_count', 1)
            ->where('feedback', function ($rows) {
                $rows = collect($rows);
                $anon = $rows->firstWhere('wants_contact', false);
                $contactable = $rows->firstWhere('wants_contact', true);

                return $anon['contact'] === null
                    && $contactable['contact']['name'] === 'Sam Followup'
                    && $contactable['contact']['email'] === 'sam@example.com';
            })
        );

        $this->assertSame(1, AuditLog::where('action', 'feedback.contact_viewed')->count());
    }

    public function test_unassigned_event_manager_sees_no_feedback_from_other_events(): void
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $ownEvent = Event::factory()->create();
        $otherEvent = Event::factory()->create();
        $manager->managedEvents()->attach($ownEvent->id, ['role' => 'event_manager']);

        $attendee = User::factory()->create();
        Feedback::create(['event_id' => $ownEvent->id, 'user_id' => $attendee->id, 'rating' => 4]);
        Feedback::create(['event_id' => $otherEvent->id, 'user_id' => $attendee->id, 'rating' => 2]);

        $response = $this->actingAs($manager)->get(route('admin.feedback.index', $ownEvent));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->has('feedback', 1));

        // Another event's workspace is closed to them entirely.
        $this->actingAs($manager)->get(route('admin.feedback.index', $otherEvent))->assertForbidden();
    }
}
