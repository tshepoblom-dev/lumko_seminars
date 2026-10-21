<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An attendee's current choice to share their contact details with one
 * speaker of one event. Projection of the `speaker_contact` entries in the
 * consent ledger; written only by ConsentService.
 */
class SpeakerConnection extends Model
{
    protected $fillable = [
        'user_id',
        'event_id',
        'speaker_id',
        'note',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function speaker()
    {
        return $this->belongsTo(Speaker::class);
    }

    public function isActive(): bool
    {
        return $this->granted_at !== null && $this->revoked_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('granted_at')->whereNull('revoked_at');
    }
}
