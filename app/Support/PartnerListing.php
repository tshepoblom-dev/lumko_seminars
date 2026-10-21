<?php

namespace App\Support;

use App\Enums\PartnerFeature;
use App\Models\Event;
use App\Models\EventPartner;

/**
 * The event's partners as shown on the public home page and the attendee
 * dashboard: published, on a tier with `listing`, grouped by tier in the
 * tier order staff set. Logos only once approved.
 */
class PartnerListing
{
    /**
     * @param  bool  $linkProfiles  link to the attendee-only partner page
     *                              (tier `profile_page`) instead of the website
     * @return list<array{tier: string, partners: list<array>}>
     */
    public static function forEvent(Event $event, bool $linkProfiles = false): array
    {
        return EventPartner::with(['partner', 'tier'])
            ->where('event_id', $event->id)
            ->where('is_published', true)
            ->get()
            ->filter(fn (EventPartner $ep) => $ep->hasFeature(PartnerFeature::Listing))
            ->sortBy(fn (EventPartner $ep) => [$ep->tier->sort_order, $ep->sort_order, $ep->partner->name])
            ->groupBy(fn (EventPartner $ep) => $ep->partner_tier_id)
            ->map(fn ($group) => [
                'tier' => $group->first()->tier->name,
                'partners' => $group->map(fn (EventPartner $ep) => [
                    'name' => $ep->partner->name,
                    'logo_url' => $ep->partner->publicLogoUrl(),
                    'logo_alt' => $ep->partner->logo_alt ?: $ep->partner->name,
                    'href' => $linkProfiles && $ep->hasFeature(PartnerFeature::ProfilePage)
                        ? route('partners.show', [$event, $ep->partner])
                        : $ep->partner->website,
                    'internal' => $linkProfiles && $ep->hasFeature(PartnerFeature::ProfilePage),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
