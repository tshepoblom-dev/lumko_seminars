<?php

namespace App\Support;

use App\Models\Event;

/**
 * The event /attend registers people for: the earliest published event
 * that hasn't ended. Always derived server-side, never from the client
 * (B2). Shared by RegistrationController and StoreRegistrationRequest,
 * whose rules depend on the event's own questions.
 */
class RegistrationEvent
{
    public static function current(): ?Event
    {
        return Event::published()
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->first();
    }
}
