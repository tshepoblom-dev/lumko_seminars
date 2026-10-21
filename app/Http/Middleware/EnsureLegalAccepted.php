<?php

namespace App\Http\Middleware;

use App\Support\Legal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everyone signed in must have accepted the current Terms & Conditions and
 * Privacy Policy before using the attendee area or the admin area. People
 * who signed up after these existed accepted them on the way in; everyone
 * else — and everyone again after a version bump — is sent to /legal/accept
 * once, then back to where they were going.
 *
 * Leaving stays possible without accepting: the Account page, exporting
 * your data and deleting your account are exempt.
 */
class EnsureLegalAccepted
{
    private const EXEMPT_ROUTES = [
        'legal.*',
        'logout',
        'attendee.preferences.edit',
        'attendee.preferences.download',
        'attendee.preferences.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->routeIs(...self::EXEMPT_ROUTES) || Legal::hasAcceptedCurrent($user)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Please accept the updated Terms & Conditions and Privacy Policy first.'], 409);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('legal.accept');
    }
}
