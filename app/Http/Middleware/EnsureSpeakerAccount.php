<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The speaker portal: the signed-in user must be linked to a speaker
 * profile (speakers.user_id, set by accepting a portal invitation). The
 * profile is put on the request as `speaker` for the controllers.
 */
class EnsureSpeakerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $speaker = $request->user()?->speakerProfile;

        abort_unless($speaker, 403, 'This account is not linked to a speaker profile.');

        $request->attributes->set('speaker', $speaker);

        return $next($request);
    }
}
