<?php

namespace App\Http\Middleware;

use App\Models\PageView;
use App\Support\VisitorHash;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts a page hit for Admin → Analytics: a full page load or an Inertia
 * navigation that rendered a page. Written in terminate(), after the
 * response has gone, and never allowed to fail a request.
 *
 * Not counted: partial reloads (the Live page's polling fallback), link
 * prefetches, redirects and errors, JSON calls, file downloads, the admin
 * area, remote speaker stage links, token links (speaker report, account
 * set-up, password reset, portal invitations), the speaker and partner
 * portals, staff, and bots.
 */
class RecordPageView
{
    // Also every page whose URL carries an emailed credential (a token in
    // the path must never be stored and shown on Admin → Analytics), and
    // the private speaker and partner portals.
    private const EXCLUDED_PREFIXES = [
        'admin', 'stage', 'broadcasting', 'presence', 'up', 'storage', 'build',
        'speaker-report', 'confirm-registration', 'reset-password', 'invitation',
        'registration-not-me', 'speaker', 'partner',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->shouldRecord($request, $response)) {
                return;
            }

            $referrerHost = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST) ?: null;

            PageView::create([
                'viewed_on' => now()->toDateString(),
                'viewed_at' => now(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                'route_name' => $request->route()?->getName(),
                'visitor_hash' => VisitorHash::for($request),
                'is_authenticated' => $request->user() !== null,
                'referrer_host' => $referrerHost && $referrerHost !== $request->getHost()
                    ? mb_substr($referrerHost, 0, 255)
                    : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (! config('seminar.analytics.enabled', true) || ! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        if ($request->headers->has('X-Inertia-Partial-Component') || $request->headers->get('Purpose') === 'prefetch') {
            return false;
        }

        // A page: an Inertia visit, or a full HTML load (not JSON, not a download).
        $isInertia = $request->headers->has('X-Inertia');
        $isHtml = str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');

        if (! $isInertia && ! $isHtml) {
            return false;
        }

        $firstSegment = strtolower((string) $request->segment(1));

        if (in_array($firstSegment, self::EXCLUDED_PREFIXES, true)) {
            return false;
        }

        return ! $request->user()?->isStaff() && ! VisitorHash::isBot($request);
    }
}
