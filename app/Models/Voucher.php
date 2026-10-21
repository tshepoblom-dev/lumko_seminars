<?php

namespace App\Models;

use App\Support\VoucherCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An honorary invitation code: one complimentary in-person ticket at its
 * event. Issued, sent, revoked and redeemed through App\Services\
 * VoucherService; the registration it became points back at it through
 * event_registrations.voucher_id.
 */
class Voucher extends Model
{
    use HasFactory;

    // partner_id: issued from a partner's portal (its tier's comp_tickets cap).
    protected $fillable = ['event_id', 'partner_id', 'recipient_name', 'recipient_email', 'email_locked', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest like tickets.code; look codes up through
            // code_hash (VoucherCode::hash), never a WHERE on `code`.
            'code' => 'encrypted',
            'email_locked' => 'boolean',
            'sent_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $voucher) {
            if ($voucher->isDirty('code') && $voucher->code) {
                $voucher->code_hash = VoucherCode::hash($voucher->code);
            }
        });
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /** Set when a partner issued the code from its portal. */
    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function registration()
    {
        return $this->hasOne(EventRegistration::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRedeemable(): bool
    {
        return $this->redeemed_at === null
            && $this->revoked_at === null
            && $this->event->isRegistrationOpen();
    }

    /**
     * revoked | redeemed | closed | sent | unused. "closed": the event no
     * longer takes registrations (ended, completed, archived) — a draft
     * event's codes are simply not usable *yet*, so they stay unused/sent.
     */
    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->redeemed_at !== null => 'redeemed',
            $this->event->status !== 'draft' && ! $this->event->isRegistrationOpen() => 'closed',
            $this->sent_at !== null => 'sent',
            default => 'unused',
        };
    }
}
