<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgoraTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agora.app_id' => str_repeat('a1', 16)]);
        config(['services.agora.app_certificate' => str_repeat('b2', 16)]);
    }

    private function registeredAttendee(Event $event): User
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        return $attendee;
    }

    public function test_agora_token_is_issued_for_configured_agora_event(): void
    {
        $event = Event::factory()->create([
            'status' => 'published',
            'is_live' => true,
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
        ]);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->get(route('live.agora-token', $event->id));

        $response->assertOk();
        $response->assertJsonStructure(['appId', 'channel', 'uid', 'token', 'expiresAt']);
        $response->assertJsonPath('channel', 'power-of-data-main');
        // C4 — uid is random and per-join, never the Laravel user id, and
        // always above every presenter range (hosts, speakers, screens).
        $this->assertNotSame($attendee->id, $response->json('uid'));
        $this->assertGreaterThanOrEqual(1_000_000_000, $response->json('uid'));
        $response->assertJsonPath('screenUidOffset', 500_000_000);
    }

    // C4 — outside the live-lead window and not yet live, an attendee is refused.
    public function test_agora_token_is_refused_before_the_live_lead_window(): void
    {
        $event = Event::factory()->create([
            'status' => 'published',
            'is_live' => false,
            'starts_at' => now()->addHour(),
            'live_lead_minutes' => 15,
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
        ]);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->get(route('live.agora-token', $event->id));

        $response->assertStatus(403);
    }

    // C4 — staff can fetch a token to test even before the window opens.
    public function test_staff_can_get_a_token_before_the_live_lead_window(): void
    {
        $event = Event::factory()->create([
            'status' => 'published',
            'is_live' => false,
            'starts_at' => now()->addHour(),
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
        ]);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->get(route('live.agora-token', $event->id));

        $response->assertOk();
    }

    public function test_agora_token_404s_for_non_agora_event(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'livestream_provider' => 'youtube']);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->get(route('live.agora-token', $event->id));

        $response->assertNotFound();
    }

    public function test_agora_token_422s_when_no_channel_configured(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'livestream_provider' => 'agora', 'livestream_channel_name' => null]);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->get(route('live.agora-token', $event->id));

        $response->assertStatus(422);
    }

    // T-ACL-1 — a verified user with no registration for this event is refused.
    public function test_unregistered_user_cannot_get_a_token(): void
    {
        $event = Event::factory()->create([
            'status' => 'published',
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
        ]);
        $notRegistered = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($notRegistered)->get(route('live.agora-token', $event->id));

        // The route names a real event explicitly, so EnsureEventAttendee
        // returns 404 rather than 403 here (T-ACL-2: don't confirm via a
        // 403 that a specific id "exists but you can't touch it").
        $response->assertNotFound();
    }
}
