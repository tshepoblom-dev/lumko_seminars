<?php

use App\Http\Middleware\EnsureEventAttendee;
use App\Http\Middleware\EnsureLegalAccepted;
use App\Http\Middleware\EnsureSpeakerAccount;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordPageView;
use App\Http\Middleware\SecurityHeaders;
use App\Support\HomeRedirect;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/admin.php'));
            // Speaker and partner portals, and their invitation links.
            Route::middleware('web')->group(base_path('routes/portal.php'));
        },
    )
    // C1 — /broadcasting/auth needs `verified` (not just `auth`) since it's
    // the private-channel gate for /live, and is throttled like any other
    // authenticated action. The plain `channels:` shorthand on withRouting()
    // above doesn't accept custom middleware, hence this explicit call.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', 'verified', 'throttle:broadcasting-auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
            // Admin → Analytics page hits (terminable: writes after the response).
            RecordPageView::class,
        ]);
        // Signed-in users who hit a guest-only page (e.g. /login) go to their own home.
        $middleware->redirectUsersTo(
            fn (Request $request) => HomeRedirect::for($request->user())
        );
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            // B1 — auth + verified + registered-for-this-event + event published;
            // staff bypass. Applied to every attendee-only feature route.
            'attendee' => EnsureEventAttendee::class,
            // Current Terms & Privacy Policy accepted (attendee and admin areas).
            'legal' => EnsureLegalAccepted::class,
            // The speaker portal: the account is linked to a speaker profile.
            'speaker' => EnsureSpeakerAccount::class,
        ]);
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Stage H — branded error pages for browsers, never a stack trace.
        // Skipped while APP_DEBUG is on so developers keep the full debug
        // page, and for pure-JSON callers (e.g. the stepper's axios
        // validate call) so they still get a machine-readable response.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            $wantsHtmlOrInertia = $request->header('X-Inertia') || ! $request->expectsJson();

            if (config('app.debug') || ! $wantsHtmlOrInertia) {
                return $response;
            }

            if ($status === 419) {
                return back()->with('error', 'Your session expired. Please try again.');
            }

            if (in_array($status, [403, 404, 429, 500, 503], true)) {
                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
