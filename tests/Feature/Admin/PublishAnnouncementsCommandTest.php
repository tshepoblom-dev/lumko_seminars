<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Notifications\AnnouncementPublished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PublishAnnouncementsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_creates_in_app_notification_but_never_emails(): void
    {
        Notification::fake();

        $event = Event::factory()->create();
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $announcement = Announcement::create([
            'event_id' => $event->id, 'title' => 'Scheduled', 'body' => 'Body.',
            'type' => 'event_update', 'published_at' => now()->subMinute(),
        ]);

        $this->artisan('announcements:publish')->assertExitCode(0);

        Notification::assertSentTo($attendee, AnnouncementPublished::class);
        Notification::assertNotSentTo($attendee, AnnouncementNotification::class);
        $this->assertNotNull($announcement->fresh()->send_started_at);
        $this->assertNull($announcement->fresh()->emailed_at);
    }

    public function test_publish_is_idempotent(): void
    {
        Notification::fake();

        $event = Event::factory()->create();
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        Announcement::create([
            'event_id' => $event->id, 'title' => 'Scheduled', 'body' => 'Body.',
            'type' => 'event_update', 'published_at' => now()->subMinute(),
        ]);

        $this->artisan('announcements:publish');
        $this->artisan('announcements:publish');

        Notification::assertSentToTimes($attendee, AnnouncementPublished::class, 1);
    }

    public function test_unpublished_draft_is_not_touched(): void
    {
        Notification::fake();

        $event = Event::factory()->create();
        Announcement::create([
            'event_id' => $event->id, 'title' => 'Draft', 'body' => 'Body.',
            'type' => 'event_update', 'published_at' => null,
        ]);

        $this->artisan('announcements:publish');

        Notification::assertNothingSent();
    }
}
