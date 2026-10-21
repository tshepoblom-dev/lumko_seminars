<?php

namespace App\Console\Commands;

use App\Models\Resource;
use App\Models\User;
use App\Notifications\ResourceAvailable;
use Illuminate\Console\Command;

/**
 * D-Resources — sets `released_at` (once) and notifies registered
 * attendees the moment a published resource actually becomes available
 * per its release_mode/release_at/programme_session_id. Idempotent via
 * `released_at IS NULL` as the claim — a resource is only ever released
 * once, from whichever tick first finds it eligible.
 *
 * Uploads from the speaker and partner portals are left out: they publish
 * without emailing every attendee (staff can announce them).
 */
class ReleaseResourcesCommand extends Command
{
    protected $signature = 'resources:release';

    protected $description = 'Release published resources whose release timing has arrived and notify attendees';

    public function handle(): int
    {
        $released = 0;

        Resource::published()
            ->whereNull('released_at')
            ->whereNull('speaker_id')
            ->whereNull('partner_id')
            ->where('release_mode', '!=', 'immediate')
            ->with('event.currentSession')
            ->get()
            ->each(function (Resource $resource) use (&$released) {
                $event = $resource->event;

                if (! $resource->isReleased($event, $event->currentSession, now())) {
                    return;
                }

                $resource->update(['released_at' => now()]);

                User::whereHas('registrations', function ($query) use ($event) {
                    $query->where('event_id', $event->id)->where('status', 'registered');
                })->chunkById(200, function ($recipients) use ($resource) {
                    foreach ($recipients as $recipient) {
                        $recipient->notify(new ResourceAvailable($resource));
                    }
                });

                $released++;
            });

        // "immediate" resources are available the instant they're
        // published (is_published + no release_at check needed); still
        // worth notifying once when that publish happens, so this covers
        // them too, distinctly, since they never go through isReleased()
        // gating above (release_mode != 'immediate' filter excludes them).
        Resource::published()
            ->whereNull('released_at')
            ->whereNull('speaker_id')
            ->whereNull('partner_id')
            ->where('release_mode', 'immediate')
            ->get()
            ->each(function (Resource $resource) use (&$released) {
                $resource->update(['released_at' => now()]);

                User::whereHas('registrations', function ($query) use ($resource) {
                    $query->where('event_id', $resource->event_id)->where('status', 'registered');
                })->chunkById(200, function ($recipients) use ($resource) {
                    foreach ($recipients as $recipient) {
                        $recipient->notify(new ResourceAvailable($resource));
                    }
                });

                $released++;
            });

        $this->components->info("Released {$released} resource(s).");

        return self::SUCCESS;
    }
}
