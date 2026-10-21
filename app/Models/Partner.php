<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A business or organisation that supports events. One shared library,
 * like speakers; an event takes a partner on through event_partner, on one
 * of that event's tiers.
 */
class Partner extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'website',
        'description',
        'contact_email',
        'logo_path',
        'logo_alt',
        'logo_approved',
    ];

    // Admin-only, like speakers.contact_email.
    protected $hidden = [
        'contact_email',
    ];

    protected function casts(): array
    {
        return [
            'logo_approved' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $partner) {
            if (blank($partner->slug)) {
                $partner->slug = static::uniqueSlug($partner->name);
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'partner';
        $slug = $base;
        $n = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'event_partner')
            ->using(EventPartner::class)
            ->withPivot(['id', 'partner_tier_id', 'stand_label', 'blurb', 'is_published', 'sort_order'])
            ->withTimestamps();
    }

    public function eventPartners()
    {
        return $this->hasMany(EventPartner::class);
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'partner_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Leads: attendees who chose to share their details with this partner. */
    public function connections()
    {
        return $this->hasMany(PartnerConnection::class);
    }

    public function resources()
    {
        return $this->hasMany(Resource::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    public function conversions()
    {
        return $this->hasMany(ClientConversion::class);
    }

    public function portalInvitations()
    {
        return $this->hasMany(PortalInvitation::class);
    }

    /**
     * The partner-portal state for the admin pages: who's on the team, and
     * which invitations are still waiting to be accepted.
     */
    public function portalSummary(): array
    {
        $members = $this->members()->orderBy('users.name')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->pivot->role,
        ])->values();

        $pending = $this->portalInvitations()
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->latest('id')->get()
            ->map(fn (PortalInvitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'role' => $i->member_role ?: 'member',
                'invited_on' => $i->created_at?->format('j M Y'),
                'expires_on' => $i->expires_at?->format('j M Y'),
            ])->values();

        return [
            'members' => $members,
            'pending' => $pending,
            'contact_email' => $this->contact_email,
            'status' => $members->isNotEmpty() ? 'active' : ($pending->isNotEmpty() ? 'invited' : 'none'),
            'can_invite_owner' => filled($this->contact_email) && $members->isEmpty() && $pending->isEmpty(),
        ];
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function isOwner(User $user): bool
    {
        return $this->members()->whereKey($user->id)->wherePivot('role', 'owner')->exists();
    }

    /** The logo URL for public pages: only once staff approved it. */
    public function publicLogoUrl(): ?string
    {
        return $this->logo_path && $this->logo_approved
            ? Storage::disk('public')->url($this->logo_path)
            : null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
