<?php

namespace Tests\Concerns;

use App\Models\Event;
use App\Models\EventPartner;
use App\Models\Partner;
use App\Models\PartnerTier;
use App\Models\User;

/** Partners on an event, on a tier, with a portal team. */
trait CreatesPartners
{
    /**
     * @param  list<string>|null  $features  null = every feature
     */
    protected function partnerOn(Event $event, ?array $features = null, array $attributes = [], bool $published = true): Partner
    {
        $partner = Partner::factory()->create(['name' => 'DataWorks', ...$attributes]);
        $tier = PartnerTier::factory()->create([
            'event_id' => $event->id,
            ...($features !== null ? ['features' => $features] : []),
        ]);

        EventPartner::create([
            'event_id' => $event->id,
            'partner_id' => $partner->id,
            'partner_tier_id' => $tier->id,
            'is_published' => $published,
        ]);

        return $partner;
    }

    protected function partnerMember(Partner $partner, string $role = 'member', array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $partner->members()->attach($user->id, ['role' => $role]);

        return $user;
    }
}
