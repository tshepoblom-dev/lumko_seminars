<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\ProgrammeSession;
use Database\Seeders\PowerOfDataEventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-SEED-1 — the seeded programme must match the published times exactly:
 * 08:00–08:05 / 08:05–08:30 / 08:30–08:55 / 08:55–09:07 / 09:07–09:10 /
 * 09:10–09:20. Two of these were previously off by the *talk length*
 * (23 min) rather than the actual slot boundary.
 */
class PowerOfDataEventSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_programme_matches_the_published_times(): void
    {
        (new PowerOfDataEventSeeder)->run();

        $event = Event::where('slug', 'the-power-of-data')->firstOrFail();
        $sessions = $event->programmeSessions()->orderBy('sort_order')->get();

        $expected = [
            ['08:00', '08:05'],
            ['08:05', '08:30'],
            ['08:30', '08:55'],
            ['08:55', '09:07'],
            ['09:07', '09:10'],
            ['09:10', '09:20'],
        ];

        $this->assertCount(6, $sessions);

        foreach ($sessions as $i => $session) {
            $this->assertSame($expected[$i][0], $session->start_time->format('H:i'), "Session {$i} start time");
            $this->assertSame($expected[$i][1], $session->end_time->format('H:i'), "Session {$i} end time");
        }
    }

    public function test_welcome_session_mentions_tshepo_blom_and_lumko(): void
    {
        (new PowerOfDataEventSeeder)->run();

        $welcome = ProgrammeSession::where('sort_order', 1)->firstOrFail();

        $this->assertStringContainsString('Tshepo Blom', $welcome->description);
        $this->assertStringContainsString('Lumko', $welcome->description);
    }

    public function test_seeding_is_idempotent(): void
    {
        (new PowerOfDataEventSeeder)->run();
        (new PowerOfDataEventSeeder)->run();

        $this->assertSame(1, Event::where('slug', 'the-power-of-data')->count());
        $this->assertSame(6, ProgrammeSession::count());
    }
}
