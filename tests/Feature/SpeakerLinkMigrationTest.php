<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_09_25_000001 moves programme_sessions.speaker_id into the new
 * event_speaker / programme_session_speaker links without losing any
 * existing session speaker.
 */
class SpeakerLinkMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_25_000001_link_speakers_to_events_and_sessions.php';

    public function test_existing_session_speakers_are_carried_over(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('programme_sessions', 'speaker_id'));

        $now = now();
        $event = DB::table('events')->insertGetId([
            'name' => 'E', 'slug' => 'e', 'starts_at' => $now, 'ends_at' => $now, 'status' => 'published',
            'online_price' => 0, 'in_person_price' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $alpha = DB::table('speakers')->insertGetId(['name' => 'Alpha', 'created_at' => $now, 'updated_at' => $now]);
        $bravo = DB::table('speakers')->insertGetId(['name' => 'Bravo', 'created_at' => $now, 'updated_at' => $now]);

        $session = fn (?int $speaker, int $order) => DB::table('programme_sessions')->insertGetId([
            'event_id' => $event, 'speaker_id' => $speaker, 'title' => "S{$order}", 'sort_order' => $order,
            'start_time' => $now, 'end_time' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $s1 = $session($bravo, 1);
        $s2 = $session($alpha, 2);
        $s3 = $session($bravo, 3);
        $s4 = $session(null, 4);

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('programme_sessions', 'speaker_id'));
        $links = DB::table('programme_session_speaker')->pluck('speaker_id', 'programme_session_id')->all();
        $this->assertEquals([$s1 => $bravo, $s2 => $alpha, $s3 => $bravo], $links);
        $this->assertArrayNotHasKey($s4, $links);

        // Each speaker linked to the event once, in programme order.
        $this->assertSame(
            [$bravo, $alpha],
            DB::table('event_speaker')->where('event_id', $event)->orderBy('sort_order')->pluck('speaker_id')->all()
        );
    }
}
