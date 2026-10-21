<?php

namespace App\Models;

use App\Enums\PartnerFeature;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A partner's involvement in one event: its tier (and so its features),
 * stand label and event-specific blurb. A real model, not just pivot
 * columns, because everything in the partner portal asks it
 * hasFeature(...).
 */
class EventPartner extends Pivot
{
    protected $table = 'event_partner';

    public $incrementing = true;

    protected $fillable = [
        'event_id',
        'partner_id',
        'partner_tier_id',
        'stand_label',
        'blurb',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function tier()
    {
        return $this->belongsTo(PartnerTier::class, 'partner_tier_id');
    }

    /** No tier means no features: a partner is listed only once staff place it. */
    public function hasFeature(PartnerFeature $feature): bool
    {
        return (bool) $this->tier?->has($feature);
    }

    /** @return list<string> */
    public function features(): array
    {
        return array_values(array_filter(
            $this->tier?->features ?? [],
            fn ($value) => PartnerFeature::tryFrom($value) !== null,
        ));
    }

    public function compTicketCap(): int
    {
        return $this->hasFeature(PartnerFeature::CompTickets) ? (int) $this->tier->comp_tickets : 0;
    }
}
