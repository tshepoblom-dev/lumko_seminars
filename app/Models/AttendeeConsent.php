<?php

namespace App\Models;

use App\Enums\ConsentType;
use Illuminate\Database\Eloquent\Model;

class AttendeeConsent extends Model
{
    protected $fillable = [
        'user_id',
        'event_id',
        'type',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isGranted(): bool
    {
        return $this->granted_at !== null && $this->revoked_at === null;
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
