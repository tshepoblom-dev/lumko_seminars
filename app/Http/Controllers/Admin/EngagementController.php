<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Feedback;
use App\Services\EventStatsService;
use Inertia\Inertia;
use Inertia\Response;

class EngagementController extends Controller
{
    public function __construct(private EventStatsService $stats) {}

    public function show(Event $event): Response
    {
        // B11 — viewAny only checks isStaff(); an event manager not
        // assigned to this specific event must not reach its engagement
        // numbers just by guessing the URL, so the event itself is
        // checked too (mirrors LiveControlController's `update` check).
        $this->authorize('viewAny', Feedback::class);
        $this->authorize('view', $event);

        return Inertia::render('Admin/Engagement/Show', [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'stats' => $this->stats->summary($event),
        ]);
    }
}
