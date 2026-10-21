<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\User;
use App\Support\AgoraStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The staff "attendee view" and the /live player's before-the-window state. */
class LivePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function agoraEvent(array $attributes = []): Event
    {
        return Event::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
            'live_lead_minutes' => 15,
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
            ...$attributes,
        ]);
    }

    public function test_assigned_staff_see_the_live_page_for_the_event_they_manage(): void
    {
        // Two events: the preview must show the one named in the URL, not
        // whichever event is currently public.
        $this->agoraEvent(['name' => 'Earlier event', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $event = $this->agoraEvent(['name' => 'The Power of Data']);
        $speaker = Speaker::factory()->create(['name' => 'Dr Herkulaas Combrink']);
        $event->speakers()->attach($speaker->id, ['sort_order' => 0]);

        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event->managers()->attach($manager->id, ['role' => 'event_manager']);

        $this->actingAs($manager)->get(route('admin.live.preview', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Live')
                ->where('preview', true)
                ->where('event.name', 'The Power of Data')
                // Staff can always join the player.
                ->where('event.within_live_window', true)
                ->where('event.presenters.'.(AgoraStage::SPEAKER_UID_BASE + $speaker->id), 'Dr Herkulaas Combrink'));
    }

    public function test_unassigned_staff_and_attendees_cannot_open_the_preview(): void
    {
        $event = $this->agoraEvent();

        $this->actingAs(User::factory()->create(['role' => UserRole::EventManager]))
            ->get(route('admin.live.preview', $event))->assertForbidden();

        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);
        $this->actingAs($attendee)->get(route('admin.live.preview', $event))->assertForbidden();
    }

    public function test_attendees_before_the_window_are_told_when_the_player_opens(): void
    {
        $event = $this->agoraEvent();
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $this->actingAs($attendee)->get(route('live.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Live')
                ->where('preview', false)
                ->where('event.within_live_window', false)
                ->where('event.opens_at', $event->starts_at->copy()->subMinutes(15)->toIso8601String()));

        $this->actingAs($attendee)->get(route('attendee.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.within_live_window', false)
                ->where('event.opens_at', $event->starts_at->copy()->subMinutes(15)->toIso8601String()));
    }

    public function test_the_home_page_flags_a_live_event(): void
    {
        $event = $this->agoraEvent(['starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHour(), 'is_live' => true]);

        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('event.is_live', true));

        $event->update(['is_live' => false]);

        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('event.is_live', false));
    }
}
