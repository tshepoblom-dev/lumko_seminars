<?php

namespace App\Services;

use App\Models\Event;
use App\Models\LivePresence;
use App\Models\PageView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Admin → Analytics, the Live page's online count and the control room.
 *
 * "Unique visitors" are distinct daily visitor hashes (VisitorHash), so
 * they're counted per day: the hash rotates at midnight, so a total across
 * days would count a returning visitor once per day, and is labelled
 * "visitor-days" rather than presented as unique people.
 */
class AnalyticsService
{
    /** A session counts as live if its heartbeat (every 30s) was seen this recently. */
    public const LIVE_WINDOW_SECONDS = 90;

    /**
     * Signed-in attendees on this event's Live page right now. Cached for a
     * few seconds: every viewer's heartbeat asks for it.
     */
    public function watching(int $eventId): int
    {
        return Cache::remember("analytics:watching:{$eventId}", 10, fn () => LivePresence::query()
            ->where('event_id', $eventId)
            ->where('route_name', 'live.show')
            ->where('last_seen_at', '>=', now()->subSeconds(self::LIVE_WINDOW_SECONDS))
            ->distinct()
            ->count('user_id'));
    }

    public function liveNow(): array
    {
        $live = fn () => LivePresence::query()->where('last_seen_at', '>=', now()->subSeconds(self::LIVE_WINDOW_SECONDS));

        $watching = $live()
            ->where('route_name', 'live.show')
            ->whereNotNull('event_id')
            ->selectRaw('event_id, count(distinct user_id) as viewers')
            ->groupBy('event_id')
            ->pluck('viewers', 'event_id');

        $eventNames = Event::whereIn('id', $watching->keys())->pluck('name', 'id');

        return [
            'total' => $live()->count(),
            'signed_in' => $live()->whereNotNull('user_id')->distinct()->count('user_id'),
            'watching_total' => (int) $watching->sum(),
            'watching' => $watching->map(fn ($viewers, $eventId) => [
                'event_id' => (int) $eventId,
                'event' => $eventNames[$eventId] ?? 'Event #'.$eventId,
                'viewers' => (int) $viewers,
            ])->values()->all(),
            'pages' => $live()
                ->selectRaw('path, count(*) as sessions')
                ->groupBy('path')
                ->orderByDesc('sessions')
                ->limit(10)
                ->toBase()
                ->get()
                ->map(fn ($row) => ['path' => $row->path, 'sessions' => (int) $row->sessions])
                ->all(),
        ];
    }

    /**
     * One row per day for the last $days days (today included), zero-filled.
     *
     * @return array<int, array{date: string, visitors: int, hits: int}>
     */
    public function daily(int $days): array
    {
        $today = CarbonImmutable::today();
        $start = $today->subDays($days - 1);

        $rows = PageView::query()
            ->where('viewed_on', '>=', $start->toDateString())
            ->selectRaw('viewed_on, count(*) as hits, count(distinct visitor_hash) as visitors')
            ->groupBy('viewed_on')
            ->toBase()
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->viewed_on, 0, 10));

        $series = [];

        for ($day = $start; $day->lte($today); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $series[] = [
                'date' => $day->toDateString(),
                'visitors' => (int) ($row->visitors ?? 0),
                'hits' => (int) ($row->hits ?? 0),
            ];
        }

        return $series;
    }

    /** @return array<int, array{path: string, hits: int, visitors: int}> */
    public function topPages(int $days, int $limit = 10): array
    {
        return PageView::query()
            ->where('viewed_on', '>=', CarbonImmutable::today()->subDays($days - 1)->toDateString())
            ->selectRaw('path, count(*) as hits, count(distinct visitor_hash) as visitors')
            ->groupBy('path')
            ->orderByDesc('hits')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn ($row) => ['path' => $row->path, 'hits' => (int) $row->hits, 'visitors' => (int) $row->visitors])
            ->all();
    }

    /** @return array<int, array{host: string, hits: int}> */
    public function topReferrers(int $days, int $limit = 10): array
    {
        return PageView::query()
            ->where('viewed_on', '>=', CarbonImmutable::today()->subDays($days - 1)->toDateString())
            ->whereNotNull('referrer_host')
            ->selectRaw('referrer_host, count(*) as hits')
            ->groupBy('referrer_host')
            ->orderByDesc('hits')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn ($row) => ['host' => $row->referrer_host, 'hits' => (int) $row->hits])
            ->all();
    }

    /** Today against yesterday, for the headline figures. */
    public function today(): array
    {
        $count = fn (string $date) => PageView::query()
            ->where('viewed_on', $date)
            ->selectRaw('count(*) as hits, count(distinct visitor_hash) as visitors')
            ->toBase()
            ->first();

        $today = $count(CarbonImmutable::today()->toDateString());
        $yesterday = $count(CarbonImmutable::yesterday()->toDateString());

        return [
            'visitors' => (int) ($today->visitors ?? 0),
            'hits' => (int) ($today->hits ?? 0),
            'visitors_yesterday' => (int) ($yesterday->visitors ?? 0),
            'hits_yesterday' => (int) ($yesterday->hits ?? 0),
        ];
    }
}
