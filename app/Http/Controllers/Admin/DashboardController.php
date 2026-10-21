<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Speaker;
use App\Services\EventStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private EventStatsService $stats) {}

    public function index(Request $request): Response
    {
        // D-Admin dashboard (spec §21) — scoped by event assignment, with
        // an event selector; a super admin sees every event, an event
        // manager only the ones assigned to them (Event::visibleTo()).
        $events = Event::visibleTo(Auth::user())->orderByDesc('starts_at')->get(['id', 'name']);

        $selectedEventId = (int) $request->integer('event', $events->first()?->id ?? 0);
        $selectedEvent = $events->firstWhere('id', $selectedEventId);

        $eventStats = $selectedEvent
            ? Cache::remember("admin.dashboard.event.{$selectedEvent->id}", 30, fn () => [
                ...$this->stats->summary($selectedEvent),
                ...$this->stats->dashboardExtras($selectedEvent),
            ])
            : null;

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'events' => Event::count(),
                'published_events' => Event::published()->count(),
                'speakers' => Speaker::count(),
            ],
            'warnings' => [
                // Attendees cannot pay if the bank details were never configured (.env).
                'payment_details_missing' => ! collect(['account_name', 'bank', 'account_number', 'branch_code'])
                    ->every(fn (string $key) => filled(config("seminar.payment.{$key}"))),
                // Every email is queued. Jobs sitting unprocessed for 10+
                // minutes mean the queue worker isn't running: registration
                // and "set your password" emails silently never leave.
                'stalled_jobs' => $this->stalledJobs(),
                'failed_jobs' => $this->countSafely('failed_jobs'),
            ],
            'events' => $events,
            'selectedEventId' => $selectedEvent?->id,
            'eventStats' => $eventStats,
        ]);
    }

    private function stalledJobs(): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        try {
            return DB::table(config('queue.connections.database.table', 'jobs'))
                ->whereNull('reserved_at')
                ->where('created_at', '<=', now()->subMinutes(10)->getTimestamp())
                ->count();
        } catch (QueryException) {
            return 0;
        }
    }

    private function countSafely(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (QueryException) {
            return 0;
        }
    }
}