<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** An invitation to the speaker or partner portal (PortalInvitationService). */
class PortalInvitation extends Model
{
    use HasFactory;

    public const KIND_SPEAKER = 'speaker';

    public const KIND_PARTNER = 'partner';

    protected $fillable = [
        'kind',
        'email',
        'speaker_id',
        'partner_id',
        'member_role',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_by',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function speaker()
    {
        return $this->belongsTo(Speaker::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** What the invitation is for, in words ("Naledi Dube's speaker portal"). */
    public function subjectName(): string
    {
        return $this->kind === self::KIND_SPEAKER
            ? (string) $this->speaker?->name
            : (string) $this->partner?->name;
    }
}
