<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Support\TicketCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = ['event_registration_id', 'code', 'status', 'issued_at'];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'issued_at' => 'datetime',
            // B5 — the raw QR/display code is encrypted at rest. Every
            // lookup goes through `code_hash` (a deterministic HMAC, kept
            // in sync below); a WHERE on `code` directly can never match,
            // since Laravel's encrypted cast is non-deterministic.
            'code' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $ticket) {
            if ($ticket->isDirty('code') && $ticket->code) {
                $ticket->code_hash = TicketCode::hash($ticket->code);
            }
        });
    }

    public function registration()
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function checkIn()
    {
        return $this->hasOne(CheckIn::class);
    }
}
