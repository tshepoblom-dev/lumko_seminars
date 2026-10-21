<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An attendee's current choice to share their contact details with one
 * partner of one event: a lead. Projection of the `partner_contact`
 * entries in the consent ledger; written only by ConsentService. A partner
 * only ever sees active ones (withdrawn = gone from its portal at once).
 */
class PartnerConnection extends Model
{
    public const SOURCES = ['connect', 'enquiry', 'badge_scan', 'registration'];

    protected $fillable = [
        'user_id',
        'event_id',
        'partner_id',
        'source',
        'note',
        'captured_by_user_id',
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

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function capturedBy()
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function notes()
    {
        return $this->hasMany(PartnerLeadNote::class)->latest();
    }

    public function isActive(): bool
    {
        return $this->granted_at !== null && $this->revoked_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('granted_at')->whereNull('revoked_at');
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'connect' => 'Connect button',
            'enquiry' => 'Enquiry',
            'badge_scan' => 'Stand scan',
            'registration' => 'At registration',
            default => $source,
        };
    }
}
