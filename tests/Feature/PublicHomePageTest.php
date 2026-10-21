<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\ProgrammeSession;
use App\Models\Speaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicHomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_shows_published_upcoming_event(): void
    {
        $event = Event::factory()->create([
            'name' => 'The Power of Data',
            'status' => 'published',
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHour(),
        ]);

        $speaker = Speaker::factory()->create(['name' => 'Dr Herkulaas M. Combrink']);

        $event->speakers()->attach($speaker);
        ProgrammeSession::factory()->create([
            'event_id' => $event->id,
            'title' => 'AI, data science & data sovereignty',
            'sort_order' => 1,
        ])->speakers()->attach($speaker);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('AI, data science');
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->where('event.name', 'The Power of Data')
            // The programme is for registered attendees (dashboard only):
            // it must not reach the public page's JSON at all.
            ->missing('event.programme')
            ->has('event.speakers', 1)
            ->where('series.name', 'Lumko Seminars')
            ->has('series.pillars', 3)
            ->where('series.pillars.0.name', 'Technology')
            ->has('series.example_sectors')
        );
    }

    public function test_home_page_handles_no_published_event(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->where('event', null)
            // The series is explained even between events.
            ->has('series.pillars', 3)
        );
    }

    // D-Public home — the home page keeps showing the event for a while
    // after it ends (a real post-event state), rather than dropping to
    // the "no event published" fallback the instant it's over.
    public function test_home_page_shows_post_event_state_within_the_post_event_window(): void
    {
        Event::factory()->create([
            'name' => 'The Power of Data', 'status' => 'published',
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(),
            'recording_url' => 'https://example.com/recording',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->where('event.name', 'The Power of Data')
            ->where('event.has_ended', true)
            ->where('event.recording_url', 'https://example.com/recording')
        );
    }

    public function test_home_page_drops_the_event_outside_the_post_event_window(): void
    {
        Event::factory()->create([
            'status' => 'published',
            'starts_at' => now()->subDays(200), 'ends_at' => now()->subDays(199),
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->where('event', null)
        );
    }
}
