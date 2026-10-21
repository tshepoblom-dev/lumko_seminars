<?php

namespace App\Console\Commands;

use App\Models\LivePresence;
use App\Models\PageView;
use Illuminate\Console\Command;

/**
 * Keeps the analytics tables small and short-lived: presence rows (which
 * name a signed-in user) go an hour after their last heartbeat, and page
 * hits after seminar.analytics.retention_days.
 */
class PruneAnalyticsCommand extends Command
{
    protected $signature = 'analytics:prune';

    protected $description = 'Delete stale live-presence rows and page hits past the retention window';

    public function handle(): int
    {
        $presences = LivePresence::where('last_seen_at', '<', now()->subHour())->delete();

        $retentionDays = max(1, (int) config('seminar.analytics.retention_days', 365));
        $pageViews = PageView::where('viewed_on', '<', now()->subDays($retentionDays)->toDateString())->delete();

        $this->info("Deleted {$presences} presence row(s) and {$pageViews} page hit(s).");

        return self::SUCCESS;
    }
}
