<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One open browser session, refreshed by the page heartbeat
 * (App\Http\Controllers\PresenceController). `event_id` is only set for an
 * attendee on the Live page of an event they're registered for.
 */
class LivePresence extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_hash',
        'user_id',
        'event_id',
        'route_name',
        'path',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
