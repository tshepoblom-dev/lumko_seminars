<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\ProgrammeSession;
use App\Models\Resource;
use App\Models\User;
use App\Notifications\EventReminder1h;
use App\Notifications\EventReminder24h;
use App\Notifications\EventStartingSoon;
use App\Notifications\PostEventThankYou;
use App\Notifications\ResourceAvailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SchedulerCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function registeredAttendee(Event $event): User
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        return $attendee;
    }

    // reminders:send
    public function test_24h_reminder_sent_once_within_window(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->addHours(20), 'ends_at' => now()->addHours(22)]);
        $attendee = $this->registeredAttendee($event);

        $this->artisan('reminders:send');
        $this->artisan('reminders:send'); // idempotent

        Notification::assertSentToTimes($attendee, EventReminder24h::class, 1);
    }

    public function test_1h_reminder_not_sent_outside_window(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(7)]);
        $attendee = $this->registeredAttendee($event);

        $this->artisan('reminders:send');

        Notification::assertNotSentTo($attendee, EventReminder1h::class);
    }

    public function test_starting_soon_sent_within_live_lead_window(): void
    {
        Notification::fake();
        $event = Event::factory()->create([
            'status' => 'published', 'is_live' => false,
            'starts_at' => now()->addMinutes(10), 'ends_at' => now()->addHours(2), 'live_lead_minutes' => 15,
        ]);
        $attendee = $this->registeredAttendee($event);

        $this->artisan('reminders:send');

        Notification::assertSentTo($attendee, EventStartingSoon::class);
    }

    // resources:release
    public function test_immediate_resource_is_released_and_notifies_once(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);
        $resource = Resource::factory()->create([
            'event_id' => $event->id, 'is_published' => true, 'release_mode' => 'immediate', 'external_url' => 'https://example.com',
        ]);

        $this->artisan('resources:release');
        $this->artisan('resources:release');

        $this->assertNotNull($resource->fresh()->released_at);
        Notification::assertSentToTimes($attendee, ResourceAvailable::class, 1);
    }

    public function test_before_event_resource_waits_for_release_at(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);
        $resource = Resource::factory()->create([
            'event_id' => $event->id, 'is_published' => true, 'release_mode' => 'before_event',
            'release_at' => now()->addDay(), 'external_url' => 'https://example.com',
        ]);

        $this->artisan('resources:release');

        $this->assertNull($resource->fresh()->released_at);
    }

    public function test_during_session_resource_releases_once_that_session_is_current(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published']);
        $session = ProgrammeSession::factory()->create(['event_id' => $event->id]);
        $resource = Resource::factory()->create([
            'event_id' => $event->id, 'is_published' => true, 'release_mode' => 'during_session',
            'programme_session_id' => $session->id, 'external_url' => 'https://example.com',
        ]);

        $this->artisan('resources:release');
        $this->assertNull($resource->fresh()->released_at);

        $event->update(['current_session_id' => $session->id]);
        $this->artisan('resources:release');
        $this->assertNotNull($resource->fresh()->released_at);
    }

    // postevent:send
    public function test_post_event_thank_you_sent_once_after_event_ends(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->subDay(), 'ends_at' => now()->subHour()]);
        $attendee = $this->registeredAttendee($event);

        $this->artisan('postevent:send');
        $this->artisan('postevent:send');

        Notification::assertSentToTimes($attendee, PostEventThankYou::class, 1);
    }

    public function test_post_event_not_sent_before_event_ends(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
        $attendee = $this->registeredAttendee($event);

        $this->artisan('postevent:send');

        Notification::assertNotSentTo($attendee, PostEventThankYou::class);
    }
}
