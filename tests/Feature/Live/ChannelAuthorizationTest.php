<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * T-RT-3 — exercises routes/channels.php's authorization closures
 * directly (resolved from Broadcast::getChannels()), rather than
 * simulating a full Pusher /broadcasting/auth handshake.
 */
class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function channelCallback(string $pattern): \Closure
    {
        $channels = Broadcast::getChannels();
        $this->assertArrayHasKey($pattern, $channels, "Channel pattern '{$pattern}' is not registered.");

        return $channels[$pattern];
    }

    public function test_unregistered_verified_user_is_denied_on_event_channel(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Attendee, 'email_verified_at' => now()]);

        $result = ($this->channelCallback('event.{eventId}'))($user, $event->id);

        $this->assertFalse((bool) $result);
    }

    public function test_unverified_registered_attendee_is_denied_on_event_channel(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->unverified()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $result = ($this->channelCallback('event.{eventId}'))($user, $event->id);

        $this->assertFalse((bool) $result);
    }

    public function test_verified_registered_attendee_is_allowed_on_event_channel(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Attendee, 'email_verified_at' => now()]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $result = ($this->channelCallback('event.{eventId}'))($user, $event->id);

        $this->assertTrue((bool) $result);
    }

    public function test_unassigned_staff_is_denied_on_admin_event_channel(): void
    {
        $event = Event::factory()->create();
        $manager = User::factory()->create(['role' => UserRole::EventManager]);

        $result = ($this->channelCallback('admin.event.{eventId}'))($manager, $event->id);

        $this->assertFalse((bool) $result);
    }

    public function test_assigned_staff_is_allowed_on_admin_event_channel(): void
    {
        $event = Event::factory()->create();
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event->managers()->attach($manager->id, ['role' => 'event_manager']);

        $result = ($this->channelCallback('admin.event.{eventId}'))($manager, $event->id);

        $this->assertTrue((bool) $result);
    }

    public function test_super_admin_is_always_allowed_on_admin_event_channel(): void
    {
        $event = Event::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $result = ($this->channelCallback('admin.event.{eventId}'))($admin, $event->id);

        $this->assertTrue((bool) $result);
    }
}
