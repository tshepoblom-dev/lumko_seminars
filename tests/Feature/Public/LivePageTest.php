<?php

namespace Tests\Feature\Public;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LivePageTest extends TestCase
{
    use RefreshDatabase;

    private function registeredAttendee(Event $event): User
    {
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        return $user;
    }

    public function test_live_page_shows_the_embed_while_the_event_is_ongoing(): void
    {
        $event = Event::factory()->create([
            'status' => 'published', 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHour(), 'is_live' => true,
        ]);
        $user = $this->registeredAttendee($event);

        $response = $this->actingAs($user)->get(route('live.show'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('event.has_ended', false)
        );
    }

    // D-Public home — /live shows the recording (or "session ended") once
    // the event is over, not a stale live embed.
    public function test_live_page_reports_ended_state_with_recording_url_after_event_ends(): void
    {
        $event = Event::factory()->create([
            'status' => 'published', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(),
            'recording_url' => 'https://example.com/recording',
        ]);
        $user = $this->registeredAttendee($event);

        $response = $this->actingAs($user)->get(route('live.show'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('event.has_ended', true)
            ->where('event.recording_url', 'https://example.com/recording')
        );
    }

    public function test_unregistered_verified_user_cannot_view_live_page(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        $user = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($user)->get(route('live.show'));

        $response->assertForbidden();
    }
}
