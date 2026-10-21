<?php

namespace App\Models;

use App\Enums\PartnerFeature;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** An event's partnership level (Gold, Silver…) and what it unlocks. */
class PartnerTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'name',
        'sort_order',
        'features',
        'comp_tickets',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'sort_order' => 'integer',
            'comp_tickets' => 'integer',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function eventPartners()
    {
        return $this->hasMany(EventPartner::class);
    }

    public function has(PartnerFeature $feature): bool
    {
        return in_array($feature->value, $this->features ?? [], true);
    }
}
