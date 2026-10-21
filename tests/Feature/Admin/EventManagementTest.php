<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_manager_can_view_events_index(): void
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        Event::factory()->count(2)->create();

        $response = $this->actingAs($manager)->get(route('admin.events.index'));

        $response->assertOk();
    }

    public function test_super_admin_can_create_event(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->post(route('admin.events.store'), [
            'name' => 'Test Event',
            'subtitle' => 'A subtitle',
            'slug' => 'test-event',
            'description' => 'Description',
            'venue_name' => 'Venue',
            'venue_address' => 'Address',
            'starts_at' => now()->addWeek()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addWeek()->addHour()->format('Y-m-d H:i:s'),
            'online_price' => 0,
            'in_person_price' => 150,
            'status' => 'draft',
        ]);

        $response->assertRedirect(route('admin.events.show', Event::where('slug', 'test-event')->first()));
        $this->assertDatabaseHas('events', ['slug' => 'test-event']);
    }

    public function test_attendee_cannot_access_admin_area(): void
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($attendee)->get(route('admin.dashboard'));

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_admin_area(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('login'));
    }

    // D5 frontend polish — the index page tells the admin upfront whether
    // an event can be deleted, instead of only finding out after a
    // confirm() + a round trip that gets bounced back with an error.
    public function test_index_marks_events_with_related_data_as_not_deletable(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $withData = Event::factory()->create(['status' => 'published']);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $withData->id, 'attendance_mode' => 'online', 'status' => 'registered']);
        $emptyDraft = Event::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($admin)->get(route('admin.events.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('events', function ($events) use ($withData, $emptyDraft) {
                $events = collect($events);
                $withDataRow = $events->firstWhere('id', $withData->id);
                $emptyDraftRow = $events->firstWhere('id', $emptyDraft->id);

                return $withDataRow['can_delete'] === false
                    && str_contains($withDataRow['blocking_summary'], 'registrations')
                    && $emptyDraftRow['can_delete'] === true
                    && $emptyDraftRow['blocking_summary'] === null;
            })
        );
    }

    // T-DEL-1 — an event with related data is refused, with counts, nothing deleted.
    public function test_event_with_registrations_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($admin)->delete(route('admin.events.destroy', $event));

        $response->assertRedirect();
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    // T-DEL-2 — the database itself refuses, independent of the app-level guard.
    public function test_raw_event_delete_throws_when_children_exist(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $this->expectException(QueryException::class);

        $event->delete();
    }

    // T-DEL-3 — archive hides the event publicly; an empty draft can still be deleted.
    public function test_archiving_hides_an_event_from_the_public_home_and_empty_draft_can_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);

        $this->actingAs($admin)->post(route('admin.events.archive', $event));
        $this->assertSame('archived', $event->fresh()->status);

        $this->assertFalse(Event::published()->whereKey($event->id)->exists());

        $emptyDraft = Event::factory()->create(['status' => 'draft']);
        $response = $this->actingAs($admin)->delete(route('admin.events.destroy', $emptyDraft));

        $response->assertRedirect(route('admin.events.index'));
        $this->assertDatabaseMissing('events', ['id' => $emptyDraft->id]);
    }
}
