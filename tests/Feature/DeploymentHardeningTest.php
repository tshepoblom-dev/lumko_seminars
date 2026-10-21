<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Stage H — the launch-checklist items that are code, not documents:
 * branded error pages with no stack traces, trusted-proxy handling, the
 * privacy-notice link, and the /up health check.
 */
class DeploymentHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::get('/_t/boom', fn () => throw new \RuntimeException('secret internal detail /var/www/app/Secret.php'));
            Route::get('/_t/expired', fn () => abort(419));
            Route::get('/_t/throttled', fn () => abort(429));
            Route::get('/_t/secure', fn (Request $request) => response()->json(['secure' => $request->isSecure()]));
        });
    }

    public function test_a_missing_page_renders_the_branded_error_page(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('/no-such-page');

        $response->assertNotFound();
        $response->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    }

    public function test_a_forbidden_page_renders_the_branded_error_page(): void
    {
        config(['app.debug' => false]);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($attendee)->get('/admin');

        $response->assertForbidden();
        $response->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
    }

    public function test_a_server_error_never_leaks_the_exception_or_a_stack_trace(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('/_t/boom');

        $response->assertStatus(500);
        $response->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 500));
        $body = $response->getContent();
        $this->assertStringNotContainsString('secret internal detail', $body);
        $this->assertStringNotContainsString('Secret.php', $body);
        $this->assertStringNotContainsString('vendor/laravel', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
    }

    public function test_too_many_requests_renders_the_branded_error_page(): void
    {
        config(['app.debug' => false]);

        $this->get('/_t/throttled')
            ->assertStatus(429)
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 429));
    }

    public function test_an_expired_page_redirects_back_with_a_message_instead_of_a_bare_419(): void
    {
        config(['app.debug' => false]);

        $response = $this->from('/login')->get('/_t/expired');

        $response->assertRedirect('/login');
        $response->assertSessionHas('error');
    }

    public function test_debug_mode_keeps_the_developer_error_page(): void
    {
        config(['app.debug' => true]);

        $response = $this->get('/no-such-page');

        $response->assertNotFound();
        $this->assertStringNotContainsString('"component":"Error"', $response->getContent());
    }

    public function test_json_callers_still_get_json_errors(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/no-such-page')->assertNotFound()->assertJsonStructure(['message']);
    }

    // Three separate tests (not one loop): the test client leaks HTTPS state
    // between requests inside a single test, which would mask the result.
    private function forwardedHttpsRequest(): TestResponse
    {
        return $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('/_t/secure');
    }

    public function test_forwarded_https_is_ignored_when_no_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->forwardedHttpsRequest()->assertJson(['secure' => false]);
    }

    public function test_forwarded_https_is_honoured_from_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.5']]);

        $this->forwardedHttpsRequest()->assertJson(['secure' => true]);
    }

    public function test_forwarded_https_is_ignored_from_an_untrusted_proxy(): void
    {
        config(['trustedproxy.proxies' => ['10.9.9.9']]);

        $this->forwardedHttpsRequest()->assertJson(['secure' => false]);
    }

    public function test_trusted_proxies_env_is_parsed_into_a_list_or_wildcard(): void
    {
        $parse = function (?string $value): mixed {
            $value === null ? putenv('TRUSTED_PROXIES') : putenv("TRUSTED_PROXIES={$value}");
            $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;

            return (require config_path('trustedproxy.php'))['proxies'];
        };

        try {
            $this->assertNull($parse(null));
            $this->assertSame('*', $parse('*'));
            $this->assertSame(['10.0.0.5', '10.0.1.0/24'], $parse('10.0.0.5, 10.0.1.0/24'));
        } finally {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }
    }

    public function test_privacy_notice_link_is_shared_only_when_configured(): void
    {
        config(['seminar.privacy_notice_url' => null]);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('seminar.privacyNoticeUrl', null));

        config(['seminar.privacy_notice_url' => 'https://lumko.example/privacy']);
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('seminar.privacyNoticeUrl', 'https://lumko.example/privacy')
            ->where('seminar.organiserName', config('seminar.organiser_legal_name'))
        );
    }

    public function test_health_check_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
