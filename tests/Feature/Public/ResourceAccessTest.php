<?php

namespace Tests\Feature\Public;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    private function registeredAttendee(Event $event): User
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        return $attendee;
    }

    public function test_attendee_cannot_view_unpublished_resource(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);
        $resource = Resource::factory()->create(['event_id' => $event->id, 'is_published' => false, 'external_url' => 'https://example.com']);

        $response = $this->actingAs($attendee)->get(route('resources.show', $resource->id));

        $response->assertForbidden();
    }

    public function test_attendee_viewing_published_resource_is_logged(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);
        $resource = Resource::factory()->create(['event_id' => $event->id, 'is_published' => true, 'external_url' => 'https://example.com/deck']);

        $response = $this->actingAs($attendee)->get(route('resources.show', $resource->id));

        $response->assertRedirect('https://example.com/deck');
        $this->assertDatabaseHas('resource_views', ['resource_id' => $resource->id, 'user_id' => $attendee->id]);
    }

    public function test_downloading_a_private_file_streams_it_not_a_public_url(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('resources/deck.pdf', 'fake-pdf-contents');

        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);
        $resource = Resource::factory()->create([
            'event_id' => $event->id,
            'is_published' => true,
            'external_url' => null,
            'file_path' => 'resources/deck.pdf',
            'file_original_name' => 'deck.pdf',
        ]);

        $response = $this->actingAs($attendee)->get(route('resources.show', $resource->id));

        $response->assertOk();
        $this->assertDatabaseHas('resource_views', ['resource_id' => $resource->id, 'action' => 'download']);
    }

    // T-ACL-1/T-ACL-2 — a user with no registration for this resource's
    // event is refused, and cross-event access isn't observable as a 403.
    public function test_unregistered_user_cannot_view_a_resource(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $resource = Resource::factory()->create(['event_id' => $event->id, 'is_published' => true, 'external_url' => 'https://example.com']);
        $notRegistered = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($notRegistered)->get(route('resources.show', $resource->id));

        $response->assertNotFound();
    }

    public function test_attendee_of_one_event_cannot_view_another_events_resource(): void
    {
        $eventA = Event::factory()->create(['status' => 'published']);
        $eventB = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($eventA);
        $resource = Resource::factory()->create(['event_id' => $eventB->id, 'is_published' => true, 'external_url' => 'https://example.com']);

        $response = $this->actingAs($attendee)->get(route('resources.show', $resource->id));

        $response->assertNotFound();
    }
}
