<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\AgoraStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AgoraTokenController extends Controller
{
    public function show(Event $event): JsonResponse
    {
        abort_unless($event->livestream_provider?->value === 'agora', 404);
        abort_unless($event->livestream_channel_name, 422, 'This event has no Agora channel configured.');

        $user = Auth::user();

        // C4 — the audience token is only issued once the event is live,
        // or within live_lead_minutes of starting; staff bypass so they
        // can join early to test.
        abort_unless(
            $user->isStaff() || $event->isWithinLiveLeadWindow(),
            403,
            'The live session has not started yet.'
        );

        // Random per-join subscriber uid, above every presenter range —
        // see AgoraStage for the layout.
        return response()->json(AgoraStage::subscriberCredentials($event));
    }
}
