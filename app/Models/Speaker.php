<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Speaker extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'title',
        'organisation',
        'contact_email',
        'bio',
        'photo_path',
        'photo_alt',
        'photo_approved',
    ];

    // Where the speaker's report link is sent; admin-only. Hidden so a
    // speaker model serialised onto a public page can never carry it.
    protected $hidden = [
        'contact_email',
    ];

    protected function casts(): array
    {
        return [
            'photo_approved' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Speakers are one shared library; an event opts a speaker in through
     * event_speaker, and a session names its speakers (from that event's
     * list) through programme_session_speaker.
     */
    public function events()
    {
        return $this->belongsToMany(Event::class)->withPivot('sort_order')->withTimestamps();
    }

    public function programmeSessions()
    {
        return $this->belongsToMany(ProgrammeSession::class)->withPivot('sort_order')->withTimestamps();
    }

    public function portalInvitations()
    {
        return $this->hasMany(PortalInvitation::class);
    }

    /** not_invited | invited | active, for the admin speaker lists. */
    public function portalStatus(): string
    {
        if ($this->user_id) {
            return 'active';
        }

        return $this->portalInvitations()
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->exists() ? 'invited' : 'not_invited';
    }

    /**
     * The speaker-portal state for the admin pages: whether they have an
     * account, or when and where the pending invitation went.
     *
     * @return array{status: string, account_email: ?string, invited_to: ?string, invited_on: ?string, expires_on: ?string, can_invite: bool}
     */
    public function portalSummary(): array
    {
        $pending = $this->user_id ? null : $this->portalInvitations()
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->latest('id')->first();

        $status = $this->user_id ? 'active' : ($pending ? 'invited' : 'not_invited');

        return [
            'status' => $status,
            'account_email' => $this->user_id ? $this->user?->email : null,
            'invited_to' => $pending?->email,
            'invited_on' => $pending?->created_at?->format('j M Y'),
            'expires_on' => $pending?->expires_at?->format('j M Y'),
            'can_invite' => $status !== 'active' && filled($this->contact_email),
        ];
    }

    /** Attendees who chose to share their contact details with this speaker. */
    public function connections()
    {
        return $this->hasMany(SpeakerConnection::class);
    }
}
