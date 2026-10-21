<?php

namespace App\Models;

use App\Enums\AttendanceMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EventRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'user_id',
        'attendance_mode',
        'interests',
        'improvement_area',
        'industry',
        'organisation_size',
        'organisation_challenge',
        'status',
        // "How did you hear about us?" (attribution only, never consent).
        'referral_source',
        'referred_by_partner_id',
    ];

    protected function casts(): array
    {
        return [
            'attendance_mode' => AttendanceMode::class,
            'interests' => 'array',
            'improvement_area' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // B8 — public_id is deliberately not fillable: every registration
        // gets one automatically so no code path can forget to set it (the
        // migration only backfilled rows that existed before this column).
        static::creating(function (self $registration) {
            $registration->public_id ??= (string) Str::ulid();
        });
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function ticket()
    {
        return $this->hasOne(Ticket::class);
    }

    /**
     * Set only by VoucherService::redeem() — deliberately not fillable, so
     * no request payload can make a registration complimentary.
     */
    /** Answers to the event's registration questions (RegistrationForm). */
    public function answers()
    {
        return $this->hasMany(RegistrationAnswer::class);
    }

    /** The partner named under "How did you hear about us?" (attribution only). */
    public function referredByPartner()
    {
        return $this->belongsTo(Partner::class, 'referred_by_partner_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function isComplimentary(): bool
    {
        return $this->voucher_id !== null;
    }
}
