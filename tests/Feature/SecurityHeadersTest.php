<?php

namespace Tests\Feature;

use Tests\TestCase;

/** T-SEC-1 — security headers present on HTML responses. */
class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_present_on_the_home_page(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertTrue(
            $response->headers->has('Content-Security-Policy-Report-Only')
                || $response->headers->has('Content-Security-Policy')
        );
    }

    public function test_csp_starts_in_report_only_mode_by_default(): void
    {
        $response = $this->get('/');

        $response->assertHeaderMissing('Content-Security-Policy');
        $this->assertTrue($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_csp_allows_pusher_and_agora_connect_sources(): void
    {
        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('*.pusher.com', $csp);
        $this->assertStringContainsString('*.agora.io', $csp);
        $this->assertStringContainsString('youtube-nocookie.com', $csp);
    }
}
