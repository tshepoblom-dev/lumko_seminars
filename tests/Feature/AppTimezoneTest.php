<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\RegistrationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The seminar runs on South African time (SAST, UTC+2, no daylight
 * saving). Event times are typed, stored and compared in that timezone.
 *
 * Replaces tests/Feature/TimezoneTest.php, which disappeared from disk on
 * 2026-10-13 and whose path could not be written again (see the Avast
 * note). Rewritten from its three test names; the checks are equivalent,
 * not a copy.
 */
class AppTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_timezone_is_south_african(): void
    {
        $this->assertSame('Africa/Johannesburg', config('app.timezone'));
        $this->assertSame('Africa/Johannesburg', date_default_timezone_get());
    }

    public function test_event_times_round_trip_with_the_sast_offset(): void
    {
        $event = Event::factory()->create([
            'starts_at' => '2026-11-13 08:00:00',
            'ends_at' => '2026-11-13 09:20:00',
        ])->fresh();

        $this->assertSame('2026-11-13 08:00', $event->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-13T08:00:00+02:00', $event->starts_at->toIso8601String());
    }

    public function test_event_stops_being_current_exactly_at_the_sast_end_time(): void
    {
        $event = Event::factory()->create([
            'status' => 'published',
            'starts_at' => '2026-11-13 08:00:00',
            'ends_at' => '2026-11-13 09:20:00',
        ]);

        $this->travelTo(Carbon::parse('2026-11-13 09:20:00', 'Africa/Johannesburg'));
        $this->assertSame($event->id, RegistrationEvent::current()?->id);

        $this->travelTo(Carbon::parse('2026-11-13 09:20:01', 'Africa/Johannesburg'));
        $this->assertNull(RegistrationEvent::current());
    }
}
