<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\Question;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendeeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_attendees_for_the_event(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();
        $attendee = User::factory()->create(['name' => 'Jordan Attendee']);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($admin)->get(route('admin.attendees.index', $event->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Attendees/Index')
            ->has('attendees.data', 1)
            ->where('attendees.data.0.name', 'Jordan Attendee')
        );
    }

    public function test_index_search_filters_by_name(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();
        $match = User::factory()->create(['name' => 'Thabo Nkosi']);
        $match->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);
        $noMatch = User::factory()->create(['name' => 'Someone Else']);
        $noMatch->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($admin)->get(route('admin.attendees.index', $event->id).'?search=Thabo');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('attendees.data', 1)
            ->where('attendees.data.0.name', 'Thabo Nkosi')
        );
    }

    public function test_index_filter_by_checked_in_only(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();

        $checkedIn = User::factory()->create();
        $registration = $checkedIn->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered']);
        $ticket = Ticket::create(['event_registration_id' => $registration->id, 'code' => 'CODE1', 'status' => 'confirmed', 'issued_at' => now()]);
        CheckIn::create(['ticket_id' => $ticket->id, 'checked_in_at' => now()]);

        $notCheckedIn = User::factory()->create();
        $notCheckedIn->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered']);

        $response = $this->actingAs($admin)->get(route('admin.attendees.index', $event->id).'?checked_in_only=1');

        $response->assertInertia(fn (Assert $page) => $page->has('attendees.data', 1));
    }

    public function test_unassigned_event_manager_cannot_view_attendees_index(): void
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event = Event::factory()->create();

        $response = $this->actingAs($manager)->get(route('admin.attendees.index', $event->id));

        $response->assertForbidden();
    }

    public function test_show_returns_profile_engagement_and_consent_timeline_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();
        $attendee = User::factory()->create(['name' => 'Naledi Mokoena', 'email' => 'naledi@example.com']);
        $registration = $attendee->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered',
        ]);

        Question::create(['event_id' => $event->id, 'user_id' => $attendee->id, 'body' => 'A question?', 'status' => 'submitted', 'normalized_hash' => 'x']);

        ConsentRecord::create([
            'user_id' => $attendee->id, 'event_id' => $event->id, 'type' => 'networking', 'action' => 'granted',
            'purpose_key' => 'networking', 'purpose_text' => 'Networking consent', 'purpose_version' => 'v1',
            'source' => 'registration', 'effective_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.attendees.show', [$event->id, $registration->id]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Attendees/Show')
            ->where('attendee.name', 'Naledi Mokoena')
            ->where('engagement.questions_submitted', 1)
            ->has('consentTimeline', 1)
        );

        $this->assertSame(1, AuditLog::where('action', 'attendee.profile_viewed')->where('subject_id', $registration->id)->count());
    }

    public function test_show_rejects_registration_from_a_different_event(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();
        $attendee = User::factory()->create();
        $registration = $attendee->registrations()->create(['event_id' => $otherEvent->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($admin)->get(route('admin.attendees.show', [$event->id, $registration->id]));

        $response->assertNotFound();
    }

    public function test_attendee_cannot_view_admin_attendee_pages(): void
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $event = Event::factory()->create();

        $response = $this->actingAs($attendee)->get(route('admin.attendees.index', $event->id));

        $response->assertForbidden();
    }
}
