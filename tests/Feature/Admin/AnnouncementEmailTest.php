<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AnnouncementEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_announcement_email_only_reaches_registered_attendees(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();
        $registered = User::factory()->create(['role' => UserRole::Attendee]);
        $registered->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);
        $notRegistered = User::factory()->create(['role' => UserRole::Attendee]);

        $announcement = Announcement::create([
            'event_id' => $event->id, 'title' => 'Reminder', 'body' => 'See you soon.',
            'type' => 'reminder', 'published_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.announcements.send-email', [$event, $announcement]));

        $response->assertRedirect();
        $this->assertNotNull($announcement->fresh()->emailed_at);

        Notification::assertSentTo($registered, AnnouncementNotification::class);
        Notification::assertNotSentTo($notRegistered, AnnouncementNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'announcement.sent', 'subject_id' => $announcement->id]);
    }

    // T-ANN-1 — a draft (unpublished) announcement cannot be emailed.
    public function test_draft_announcement_cannot_be_emailed(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();
        $announcement = Announcement::create([
            'event_id' => $event->id, 'title' => 'Draft', 'body' => 'Not ready.',
            'type' => 'reminder', 'published_at' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.announcements.send-email', [$event, $announcement]));

        $response->assertRedirect();
        $this->assertNull($announcement->fresh()->emailed_at);
        Notification::assertNothingSent();
    }

    // T-ANN-1 — a second send is blocked without explicit resend=true.
    public function test_second_send_requires_explicit_resend_confirmation(): void
    {
        Notification::fake();
        // This test deliberately hits the endpoint 3 times to exercise
        // send -> blocked-resend -> confirmed-resend; the 2/min
        // announcement-send throttle is a separate, already-covered
        // concern (RateLimiter registration in AppServiceProvider).
        $this->withoutMiddleware(ThrottleRequests::class);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();
        $registered = User::factory()->create(['role' => UserRole::Attendee]);
        $registered->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $announcement = Announcement::create([
            'event_id' => $event->id, 'title' => 'Reminder', 'body' => 'See you soon.',
            'type' => 'reminder', 'published_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.announcements.send-email', [$event, $announcement]));
        Notification::assertSentToTimes($registered, AnnouncementNotification::class, 1);

        // Without resend=true, a second attempt is refused and does not re-send.
        $this->actingAs($admin)->post(route('admin.announcements.send-email', [$event, $announcement]));
        Notification::assertSentToTimes($registered, AnnouncementNotification::class, 1);

        // With resend=true, it does.
        $this->actingAs($admin)->post(route('admin.announcements.send-email', [$event, $announcement]), ['resend' => true]);
        Notification::assertSentToTimes($registered, AnnouncementNotification::class, 2);

        $this->assertDatabaseHas('audit_logs', ['action' => 'announcement.resent', 'subject_id' => $announcement->id]);
    }

    // D-Announcements — audience targeting.
    public function test_audience_targeting_only_emails_the_selected_mode(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();
        $onlineAttendee = User::factory()->create(['role' => UserRole::Attendee]);
        $onlineAttendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);
        $inPersonAttendee = User::factory()->create(['role' => UserRole::Attendee]);
        $inPersonAttendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered']);

        $announcement = Announcement::create([
            'event_id' => $event->id, 'title' => 'In-person only', 'body' => 'Parking details.',
            'type' => 'event_update', 'audience' => 'in_person', 'published_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.announcements.send-email', [$event, $announcement]));

        Notification::assertSentTo($inPersonAttendee, AnnouncementNotification::class);
        Notification::assertNotSentTo($onlineAttendee, AnnouncementNotification::class);
    }
}
