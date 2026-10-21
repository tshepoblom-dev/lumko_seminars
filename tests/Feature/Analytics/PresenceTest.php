<?php

namespace Tests\Feature\Analytics;

use App\Models\LivePresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    /**
     * A browser sends its session cookie on every request; the test client
     * must be told to (and only sends cookies with JSON calls when asked).
     */
    private function inSession(string $letter): static
    {
        return $this->withCredentials()->withCookie(config('session.cookie'), str_repeat($letter, 40));
    }

    private function ping(array $data, string $session = 'a')
    {
        return $this->inSession($session)->withHeaders(['User-Agent' => self::BROWSER])->postJson(route('presence.ping'), $data);
    }

    public function test_a_guest_ping_records_a_presence_without_an_event(): void
    {
        $this->ping(['route' => 'home', 'path' => '/'])->assertOk()->assertJson(['online' => null]);

        $presence = LivePresence::sole();
        $this->assertNull($presence->user_id);
        $this->assertNull($presence->event_id);
        $this->assertSame('/', $presence->path);
    }

    public function test_repeated_pings_update_one_row_per_session(): void
    {
        $this->ping(['route' => 'home', 'path' => '/']);
        $this->ping(['route' => 'event.register', 'path' => '/attend']);

        $this->assertSame('/attend', LivePresence::sole()->path);
    }

    public function test_registered_attendees_on_the_live_page_are_counted_per_event(): void
    {
        $event = $this->makeEvent();
        $first = $this->registerAttendee($event)->user;
        $second = $this->registerAttendee($event)->user;

        $this->actingAs($first)->ping(['route' => 'live.show', 'path' => '/live', 'event_id' => $event->id])
            ->assertJson(['online' => 1]);

        Cache::flush();
        $this->actingAs($second)->ping(['route' => 'live.show', 'path' => '/live', 'event_id' => $event->id], session: 'b')
            ->assertJson(['online' => 2]);

        $this->assertSame(2, LivePresence::where('event_id', $event->id)->count());
    }

    public function test_an_attendee_cannot_claim_an_event_they_are_not_registered_for(): void
    {
        $theirs = $this->makeEvent();
        $other = $this->makeEvent();
        $attendee = $this->registerAttendee($theirs)->user;

        $this->actingAs($attendee)->ping(['route' => 'live.show', 'path' => '/live', 'event_id' => $other->id])
            ->assertJson(['online' => null]);

        $this->assertNull(LivePresence::sole()->event_id);
    }

    public function test_staff_are_not_counted_but_the_admin_preview_reads_the_count(): void
    {
        $event = $this->makeEvent();
        $attendee = $this->registerAttendee($event)->user;
        $this->actingAs($attendee)->ping(['route' => 'live.show', 'path' => '/live', 'event_id' => $event->id]);

        Cache::flush();
        $this->actingAs($this->makeSuperAdmin())
            ->ping(['route' => 'admin.live.preview', 'path' => "/admin/events/{$event->id}/live/preview", 'event_id' => $event->id], session: 'c')
            ->assertJson(['online' => 1]);

        $this->assertSame(1, LivePresence::count());
    }

    public function test_the_control_room_reads_the_live_count(): void
    {
        $event = $this->makeEvent();
        $attendee = $this->registerAttendee($event)->user;
        $this->actingAs($attendee)->ping(['route' => 'live.show', 'path' => '/live', 'event_id' => $event->id]);
        Cache::flush();

        $this->actingAs($this->makeManagerFor($event))->getJson(route('admin.live.online', $event))
            ->assertOk()->assertJson(['online' => 1]);
        $this->actingAs($this->makeManagerFor($this->makeEvent()))->getJson(route('admin.live.online', $event))
            ->assertForbidden();
    }

    public function test_stale_presences_are_not_counted(): void
    {
        $event = $this->makeEvent();
        $attendee = $this->registerAttendee($event)->user;
        $this->actingAs($attendee)->ping(['route' => 'live.show', 'path' => '/live', 'event_id' => $event->id]);

        $this->travel(2)->minutes();
        Cache::flush();

        $this->assertSame(0, app(\App\Services\AnalyticsService::class)->watching($event->id));
    }

    public function test_leaving_removes_the_presence(): void
    {
        $this->ping(['route' => 'home', 'path' => '/']);
        $this->assertSame(1, LivePresence::count());

        $this->inSession('b')->withHeaders(['User-Agent' => self::BROWSER])->postJson(route('presence.leave'))->assertNoContent();
        $this->assertSame(1, LivePresence::count(), 'Another session leaving must not remove this one.');

        $this->inSession('a')->withHeaders(['User-Agent' => self::BROWSER])->postJson(route('presence.leave'))->assertNoContent();

        $this->assertSame(0, LivePresence::count());
    }

    public function test_bots_are_not_recorded(): void
    {
        $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->postJson(route('presence.ping'), ['path' => '/'])->assertOk();

        $this->assertSame(0, LivePresence::count());
    }
}
