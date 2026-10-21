<?php

namespace Database\Factories;

use App\Enums\PartnerFeature;
use App\Models\Event;
use App\Models\PartnerTier;
use Illuminate\Database\Eloquent\Factories\Factory;

class PartnerTierFactory extends Factory
{
    protected $model = PartnerTier::class;

    /** By default a tier with everything, so tests switch features off as needed. */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => 'Gold',
            'sort_order' => 0,
            'features' => PartnerFeature::values(),
            'comp_tickets' => 2,
        ];
    }
}
