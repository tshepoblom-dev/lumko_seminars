<?php

namespace Tests\Feature\Analytics;

use App\Models\LivePresence;
use App\Models\PageView;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function hit(string $date, string $visitor, string $path = '/', ?string $referrer = null): void
    {
        PageView::create([
            'viewed_on' => $date,
            'viewed_at' => now(),
            'path' => $path,
            'route_name' => null,
            'visitor_hash' => str_pad($visitor, 64, '0'),
            'referrer_host' => $referrer,
        ]);
    }

    public function test_daily_counts_unique_visitors_and_hits_and_fills_empty_days(): void
    {
        $today = now()->toDateString();
        $twoDaysAgo = now()->subDays(2)->toDateString();
        $this->hit($today, 'a');
        $this->hit($today, 'a', '/attend');
        $this->hit($today, 'b');
        $this->hit($twoDaysAgo, 'a');
        $this->hit(now()->subDays(10)->toDateString(), 'z'); // outside a 7-day range

        $daily = app(AnalyticsService::class)->daily(7);

        $this->assertCount(7, $daily);
        $this->assertSame(['date' => $today, 'visitors' => 2, 'hits' => 3], $daily[6]);
        $this->assertSame(['date' => now()->subDay()->toDateString(), 'visitors' => 0, 'hits' => 0], $daily[5]);
        $this->assertSame(['date' => $twoDaysAgo, 'visitors' => 1, 'hits' => 1], $daily[4]);
    }

    public function test_today_top_pages_and_referrers(): void
    {
        $today = now()->toDateString();
        $this->hit($today, 'a', '/attend', 'www.linkedin.com');
        $this->hit($today, 'b', '/attend', 'www.linkedin.com');
        $this->hit($today, 'a', '/');
        $this->hit(now()->subDay()->toDateString(), 'c', '/');

        $analytics = app(AnalyticsService::class);

        $this->assertSame(['visitors' => 2, 'hits' => 3, 'visitors_yesterday' => 1, 'hits_yesterday' => 1], $analytics->today());
        $this->assertSame(['path' => '/attend', 'hits' => 2, 'visitors' => 2], $analytics->topPages(7)[0]);
        $this->assertSame([['host' => 'www.linkedin.com', 'hits' => 2]], $analytics->topReferrers(7));
    }

    public function test_live_now_counts_recent_sessions_and_livestream_viewers(): void
    {
        $event = $this->makeEvent(['name' => 'The Power of Data']);
        $viewer = $this->registerAttendee($event)->user;
        $row = fn (string $session, array $attributes) => LivePresence::create([
            'session_hash' => str_pad($session, 64, '0'),
            'path' => '/',
            'last_seen_at' => now(),
            ...$attributes,
        ]);
        $row('a', ['user_id' => $viewer->id, 'event_id' => $event->id, 'route_name' => 'live.show', 'path' => '/live']);
        $row('b', ['route_name' => 'home']);
        $row('c', ['route_name' => 'home', 'last_seen_at' => now()->subMinutes(5)]);

        $live = app(AnalyticsService::class)->liveNow();

        $this->assertSame(2, $live['total']);
        $this->assertSame(1, $live['signed_in']);
        $this->assertSame(1, $live['watching_total']);
        $this->assertSame([['event_id' => $event->id, 'event' => 'The Power of Data', 'viewers' => 1]], $live['watching']);
    }

    public function test_the_analytics_page_renders_for_staff_only(): void
    {
        $this->hit(now()->toDateString(), 'a');

        $this->actingAs($this->makeManagerFor($this->makeEvent()))
            ->get(route('admin.analytics.index', ['days' => 7]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Analytics/Index')
                ->where('days', 7)
                ->has('history.daily', 7)
                ->where('today.visitors', 1));

        $this->actingAs($this->registerAttendee($this->makeEvent())->user)
            ->get(route('admin.analytics.index'))
            ->assertForbidden();
    }

    public function test_prune_removes_stale_presences_and_old_page_views(): void
    {
        config(['seminar.analytics.retention_days' => 30]);
        $this->hit(now()->subDays(40)->toDateString(), 'old');
        $this->hit(now()->toDateString(), 'new');
        LivePresence::create(['session_hash' => str_pad('s', 64, '0'), 'path' => '/', 'last_seen_at' => now()->subHours(2)]);
        LivePresence::create(['session_hash' => str_pad('t', 64, '0'), 'path' => '/', 'last_seen_at' => now()]);

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertSame(1, PageView::count());
        $this->assertSame(1, LivePresence::count());
    }
}
