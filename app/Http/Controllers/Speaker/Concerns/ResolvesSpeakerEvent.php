<?php

namespace App\Http\Controllers\Speaker\Concerns;

use App\Models\Event;
use App\Models\Speaker;
use Illuminate\Http\Request;

/**
 * Speaker portal routes take an {event}; the signed-in speaker must be on
 * that event's line-up, and the event must be public (published or
 * completed). Anything else is a 404, which confirms nothing.
 */
trait ResolvesSpeakerEvent
{
    protected function speaker(Request $request): Speaker
    {
        return $request->attributes->get('speaker');
    }

    protected function assertSpeaksAt(Speaker $speaker, Event $event): void
    {
        abort_unless(in_array($event->status, ['published', 'completed'], true), 404);
        abort_unless($speaker->events()->whereKey($event->id)->exists(), 404);
    }
}
