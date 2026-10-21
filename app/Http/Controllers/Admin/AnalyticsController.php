<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site traffic for all staff: aggregate counts only, no personal data
 * (page_views holds no IP, user id or cookie; see VisitorHash).
 */
class AnalyticsController extends Controller
{
    private const RANGES = [7, 30, 90];

    public function index(Request $request, AnalyticsService $analytics): Response
    {
        $days = in_array((int) $request->query('days'), self::RANGES, true) ? (int) $request->query('days') : 30;

        // History changes slowly and is the heavy part; "live" is re-read on
        // the page's 15-second partial reload.
        $history = fn () => Cache::remember("analytics:history:{$days}", 60, fn () => [
            'daily' => $analytics->daily($days),
            'topPages' => $analytics->topPages($days),
            'topReferrers' => $analytics->topReferrers($days),
        ]);

        return Inertia::render('Admin/Analytics/Index', [
            'days' => $days,
            'ranges' => self::RANGES,
            'today' => fn () => $analytics->today(),
            'history' => $history,
            'live' => fn () => $analytics->liveNow(),
            'liveWindowSeconds' => AnalyticsService::LIVE_WINDOW_SECONDS,
        ]);
    }
}
