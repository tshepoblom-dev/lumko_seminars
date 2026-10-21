<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. No code in this application updates or deletes a
 * consent_records row — see ConsentService.
 */
class ConsentRecord extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'event_id',
        'speaker_id',
        'partner_id',
        'type',
        'action',
        'purpose_key',
        'purpose_text',
        'purpose_version',
        'ip_address',
        'user_agent',
        'source',
        'effective_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ConsentRecord $record) {
            $record->created_at ??= now();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
