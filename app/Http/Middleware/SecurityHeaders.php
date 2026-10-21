<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * B15 — applied to the whole `web` group. CSP starts in report-only mode
 * (config('seminar.csp_enforce') / SEMINAR_CSP_ENFORCE) so it can be
 * watched for false positives during the Stage H rehearsal before it's
 * switched to actually blocking anything, per the doc's own instruction.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Camera/mic for self only — the check-in QR scanner and the
        // Agora host console both need getUserMedia() on our own origin;
        // the console and the speaker stage also share screens.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), display-capture=(self), geolocation=()');

        if (app()->environment('production') && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $cspHeaderName = config('seminar.csp_enforce')
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        $response->headers->set($cspHeaderName, $this->buildCsp());

        return $response;
    }

    private function buildCsp(): string
    {
        $directives = [
            "default-src 'self'",
            // Vite's dev server injects inline styles/scripts in local dev;
            // 'unsafe-inline' on style-src is also needed for Tailwind's
            // arbitrary-value utility classes compiled to inline styles.
            "script-src 'self'".(app()->environment('local') ? " 'unsafe-eval'" : ''),
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net",
            "img-src 'self' data: https:",
            // Pusher (real-time) — wss for the socket, https for the
            // sockjs/xhr fallback transport and the REST auth handshake.
            "connect-src 'self' wss://*.pusher.com https://*.pusher.com https://*.agora.io https://*.agoraio.cn wss://*.agora.io wss://*.agoraio.cn",
            // Agora's WebRTC media path.
            "media-src 'self' blob:",
            // Secondary/fallback livestream embeds + the recording link.
            "frame-src 'self' https://www.youtube-nocookie.com https://www.facebook.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        return implode('; ', $directives);
    }
}
