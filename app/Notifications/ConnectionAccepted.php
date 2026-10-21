<?php

namespace App\Notifications;

use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** D-Networking — database-only (in-app); see ConnectionRequested for why. */
class ConnectionAccepted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Connection $networkConnection) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $accepterName = $this->networkConnection->recipient?->name ?? 'They';

        return [
            'connection_id' => $this->networkConnection->id,
            'message' => "{$accepterName} accepted your connection request.",
        ];
    }
}
