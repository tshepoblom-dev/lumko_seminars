<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\ProgrammeSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveControlRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_go_live_and_set_current_session(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);
        $session = ProgrammeSession::factory()->create(['event_id' => $event->id]);

        $this->actingAs($admin)->post(route('admin.live.go-live', $event));
        $this->assertTrue($event->fresh()->is_live);

        $this->actingAs($admin)->post(route('admin.live.set-session', [$event->id, $session->id]));
        $this->assertSame($session->id, $event->fresh()->current_session_id);
    }

    public function test_attendee_cannot_access_control_room(): void
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $event = Event::factory()->create(['status' => 'published']);

        $response = $this->actingAs($attendee)->get(route('admin.live.control-room', $event));

        $response->assertForbidden();
    }
}
