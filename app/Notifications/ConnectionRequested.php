<?php

namespace App\Notifications;

use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * D-Networking — database-only (in-app) by design; email is explicitly
 * optional per the spec and isn't built here to avoid a half-finished
 * settings toggle. See NetworkingController for where this is dispatched.
 *
 * The connection is stored as $networkConnection, not $connection —
 * Illuminate\Bus\Queueable already declares a public $connection property
 * (the queue connection name) and a same-named property of an
 * incompatible type is a fatal trait-composition conflict, not just a
 * lint warning.
 */
class ConnectionRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Connection $networkConnection) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $requesterName = $this->networkConnection->requester?->name ?? 'Someone';

        return [
            'connection_id' => $this->networkConnection->id,
            'message' => "{$requesterName} wants to connect with you.",
        ];
    }
}
