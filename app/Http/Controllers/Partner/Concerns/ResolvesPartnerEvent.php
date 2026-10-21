<?php

namespace App\Http\Controllers\Partner\Concerns;

use App\Enums\PartnerFeature;
use App\Models\Event;
use App\Models\EventPartner;
use App\Models\Partner;

/**
 * Partner portal routes are /partner/{partner}/events/{event}/…; access to
 * the partner is the route's `can:accessPortal` gate. The partner must be
 * taken on for that event (any status but archived), and each page checks
 * the feature its tier unlocks: a feature the tier lacks is a 403.
 */
trait ResolvesPartnerEvent
{
    protected function eventPartner(Partner $partner, Event $event): EventPartner
    {
        abort_if($event->status === 'archived', 404);

        $eventPartner = EventPartner::with('tier')
            ->where('partner_id', $partner->id)
            ->where('event_id', $event->id)
            ->first();

        abort_unless($eventPartner, 404);

        return $eventPartner;
    }

    protected function requireFeature(EventPartner $eventPartner, PartnerFeature $feature): void
    {
        abort_unless($eventPartner->hasFeature($feature), 403, 'Your partnership tier for this event does not include this.');
    }

    /** What every portal page needs for its layout and section tabs. */
    protected function portalProps(Partner $partner, Event $event, EventPartner $eventPartner): array
    {
        return [
            'partner' => ['slug' => $partner->slug, 'name' => $partner->name],
            'event' => ['id' => $event->id, 'name' => $event->name, 'starts_at' => $event->starts_at?->toIso8601String()],
            'tier' => $eventPartner->tier?->name,
            'features' => $eventPartner->features(),
        ];
    }
}
