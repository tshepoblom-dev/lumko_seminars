<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Events\SessionStateUpdated;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

/** T-AGORA-2 — publisher role, staff-only, uid < 100000, audited. */
class AgoraHostBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agora.app_id' => str_repeat('a1', 16)]);
        config(['services.agora.app_certificate' => str_repeat('b2', 16)]);
    }

    private function agoraEvent(): Event
    {
        return Event::factory()->create([
            'status' => 'published',
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
        ]);
    }

    public function test_unassigned_event_manager_cannot_open_the_console(): void
    {
        $event = $this->agoraEvent();
        $manager = User::factory()->create(['role' => UserRole::EventManager]);

        $response = $this->actingAs($manager)->get(route('admin.live.broadcast.show', $event));

        $response->assertForbidden();
    }

    public function test_assigned_event_manager_can_open_the_console_and_get_a_host_token(): void
    {
        $event = $this->agoraEvent();
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event->managers()->attach($manager->id, ['role' => 'event_manager']);

        $this->actingAs($manager)->get(route('admin.live.broadcast.show', $event))->assertOk();

        $response = $this->actingAs($manager)->post(route('admin.live.broadcast.token', $event));

        $response->assertOk();
        $response->assertJsonStructure(['appId', 'channel', 'uid', 'token', 'screenUid', 'screenToken', 'expiresAt']);
        $this->assertLessThan(100000, $response->json('uid'));
        $this->assertSame($manager->id, $response->json('uid'));
        // The screen share publishes from a second client with its own uid.
        $this->assertSame(500_000_000 + $manager->id, $response->json('screenUid'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'event.broadcast_token_issued', 'subject_id' => $event->id]);
    }

    public function test_a_malformed_app_id_is_reported_instead_of_issuing_an_empty_token(): void
    {
        // The token library returns '' for a non-hex App ID rather than failing.
        config(['services.agora.app_id' => 'not-a-real-app-id']);
        $event = $this->agoraEvent();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->postJson(route('admin.live.broadcast.token', $event));

        $response->assertStatus(500);
        $this->assertStringContainsString('32 hex characters', $response->json('message'));
    }
    public function test_attendee_cannot_get_a_host_token(): void
    {
        $event = $this->agoraEvent();
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($attendee)->post(route('admin.live.broadcast.token', $event));

        $response->assertForbidden();
    }

    public function test_start_and_stop_toggle_is_live_and_are_audited(): void
    {
        EventFacade::fake([SessionStateUpdated::class]);
        $event = $this->agoraEvent();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->post(route('admin.live.broadcast.start', $event));
        $this->assertTrue($event->fresh()->is_live);
        $this->assertDatabaseHas('audit_logs', ['action' => 'event.broadcast_started', 'subject_id' => $event->id]);

        $this->actingAs($admin)->post(route('admin.live.broadcast.stop', $event));
        $this->assertFalse($event->fresh()->is_live);
        $this->assertDatabaseHas('audit_logs', ['action' => 'event.broadcast_stopped', 'subject_id' => $event->id]);

        // Attendees' LIVE badge flips in real time, as with the Control room.
        EventFacade::assertDispatchedTimes(SessionStateUpdated::class, 2);
    }
}
