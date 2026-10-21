<?php

namespace Tests\Feature\Analytics;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\PageView;
use App\Support\VisitorHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

class RecordPageViewTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    private function inertiaHeaders(array $extra = []): array
    {
        $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));

        return ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'User-Agent' => self::BROWSER, ...$extra];
    }

    public function test_a_full_page_load_is_counted_without_storing_the_ip_or_user(): void
    {
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Referer' => 'https://www.linkedin.com/feed/'])
            ->get('/privacy')->assertOk();

        $view = PageView::sole();
        $this->assertSame('/privacy', $view->path);
        $this->assertSame('legal.privacy', $view->route_name);
        $this->assertSame(now()->toDateString(), $view->viewed_on);
        $this->assertSame('www.linkedin.com', $view->referrer_host);
        $this->assertFalse($view->is_authenticated);
        $this->assertSame(64, strlen($view->visitor_hash));
        $this->assertStringNotContainsString('127.0.0.1', json_encode($view->getAttributes()));
    }

    public function test_an_inertia_navigation_is_counted_but_partial_reloads_and_prefetches_are_not(): void
    {
        $this->withHeaders($this->inertiaHeaders())->get('/terms')->assertOk();
        $this->withHeaders($this->inertiaHeaders(['X-Inertia-Partial-Component' => 'Public/Legal', 'X-Inertia-Partial-Data' => 'x']))->get('/terms');
        $this->withHeaders($this->inertiaHeaders(['Purpose' => 'prefetch']))->get('/terms');

        $this->assertSame(1, PageView::count());
    }

    public function test_admin_pages_staff_bots_posts_and_errors_are_not_counted(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->withHeaders(['User-Agent' => self::BROWSER])->get('/privacy');
        $this->actingAs($admin)->withHeaders(['User-Agent' => self::BROWSER])->get(route('admin.dashboard'));
        auth()->logout();

        $this->withHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])->get('/privacy');
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 HeadlessChrome/140.0'])->get('/privacy');
        $this->withHeaders(['User-Agent' => self::BROWSER])->get('/no-such-page')->assertNotFound();
        $this->withHeaders(['User-Agent' => self::BROWSER])->post('/presence', ['path' => '/']);

        $this->assertSame(0, PageView::count());
    }

    public function test_a_signed_in_attendee_is_counted_as_authenticated(): void
    {
        $attendee = $this->registerAttendee($this->makeEvent())->user;

        $this->actingAs($attendee)->withHeaders(['User-Agent' => self::BROWSER])->get('/privacy');

        $this->assertTrue(PageView::sole()->is_authenticated);
    }

    public function test_nothing_is_counted_when_analytics_is_disabled(): void
    {
        config(['seminar.analytics.enabled' => false]);

        $this->withHeaders(['User-Agent' => self::BROWSER])->get('/privacy');

        $this->assertSame(0, PageView::count());
    }

    public function test_the_visitor_hash_is_stable_within_a_day_and_changes_the_next(): void
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => self::BROWSER]);

        $this->travelTo(now()->setTime(9, 0));
        $morning = VisitorHash::for($request);
        $this->travelTo(now()->setTime(21, 0));
        $evening = VisitorHash::for($request);
        $this->travelTo(now()->addDay()->setTime(9, 0));
        $tomorrow = VisitorHash::for($request);

        $this->assertSame($morning, $evening);
        $this->assertNotSame($morning, $tomorrow);
        $this->assertStringNotContainsString('203.0.113.9', $morning);
    }
}
