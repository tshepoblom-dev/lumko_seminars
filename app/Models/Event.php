<?php

namespace App\Models;

use App\Enums\LivestreamProvider;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subtitle',
        'slug',
        'description',
        'venue_name',
        'venue_address',
        'hero_image_path',
        'hero_image_alt',
        'venue_image_path',
        'venue_image_alt',
        'starts_at',
        'ends_at',
        'online_price',
        'in_person_price',
        // Off: in person only (registration, public wording). See the migration.
        'offers_online',
        'status',
        'livestream_provider',
        'livestream_url',
        'livestream_channel_name',
        'livestream_secondary_provider',
        'livestream_secondary_url',
        'recording_url',
        'show_reactions_to_attendees',
        'live_lead_minutes',
        'current_session_id',
        'is_live',
        'player_open',
        'registration_page_titles',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'online_price' => 'decimal:2',
            'in_person_price' => 'decimal:2',
            'offers_online' => 'boolean',
            'livestream_provider' => LivestreamProvider::class,
            'livestream_secondary_provider' => LivestreamProvider::class,
            'is_live' => 'boolean',
            'player_open' => 'boolean',
            'show_reactions_to_attendees' => 'boolean',
            'live_lead_minutes' => 'integer',
            'registration_page_titles' => 'array',
        ];
    }

    /**
     * Whether people can register to attend online. A model created in
     * code and not yet reloaded has no value until the database default
     * (on) is read back, so a missing value counts as on.
     */
    public function offersOnline(): bool
    {
        return $this->offers_online ?? true;
    }

    /**
     * C4 — whether attendees can open the live player (the Agora audience
     * token endpoint, the Join button): the event is live, staff opened the
     * player early from the Control room, or we're within live_lead_minutes
     * of starts_at.
     */
    public function isWithinLiveLeadWindow(): bool
    {
        return $this->is_live || $this->player_open || $this->isWithinScheduledLeadWindow();
    }

    /**
     * The time-based rule on its own, ignoring the staff override — for
     * things that mean "the event is actually about to start", such as the
     * "Starting now" email, which must not go out because the player was
     * opened days early for a rehearsal.
     */
    public function isWithinScheduledLeadWindow(): bool
    {
        return $this->liveOpensAt()?->isPast() ?? false;
    }

    /** When attendees can first open the live player (live_lead_minutes before the start). */
    public function liveOpensAt(): ?CarbonInterface
    {
        return $this->starts_at?->copy()->subMinutes((int) $this->live_lead_minutes);
    }

    public function programmeSessions()
    {
        return $this->hasMany(ProgrammeSession::class)->orderBy('sort_order');
    }

    /**
     * The speakers linked to this event from the shared speaker library,
     * in the order staff arranged them (the public "Speakers" section).
     */
    public function speakers()
    {
        return $this->belongsToMany(Speaker::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('speakers.name');
    }

    /** The questions on this event's registration form (RegistrationForm). */
    public function registrationQuestions()
    {
        return $this->hasMany(RegistrationQuestion::class)->orderBy('page')->orderBy('sort_order')->orderBy('id');
    }

    /** The partners supporting this event, through event_partner. */
    public function partners()
    {
        return $this->belongsToMany(Partner::class, 'event_partner')
            ->using(EventPartner::class)
            ->withPivot(['id', 'partner_tier_id', 'stand_label', 'blurb', 'is_published', 'sort_order'])
            ->withTimestamps();
    }

    public function eventPartners()
    {
        return $this->hasMany(EventPartner::class);
    }

    public function partnerTiers()
    {
        return $this->hasMany(PartnerTier::class)->orderBy('sort_order')->orderBy('id');
    }

    public function currentSession()
    {
        return $this->belongsTo(ProgrammeSession::class, 'current_session_id');
    }

    public function registrations()
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function payments()
    {
        return $this->hasManyThrough(Payment::class, EventRegistration::class);
    }

    public function resources()
    {
        return $this->hasMany(Resource::class);
    }

    public function announcements()
    {
        return $this->hasMany(Announcement::class);
    }

    public function communications()
    {
        return $this->hasMany(Communication::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * Attendees can choose to connect with this event's speakers from the
     * moment it's published until the feedback window closes.
     */
    public function acceptsSpeakerConnections(): bool
    {
        if (! in_array($this->status, ['published', 'completed'], true)) {
            return false;
        }

        $end = $this->ends_at ?? $this->starts_at;

        return ! $end || $end->copy()->addDays((int) config('seminar.feedback_days', 30))->isFuture();
    }

    /** Same window as speakers: from publishing until the feedback window closes. */
    public function acceptsPartnerConnections(): bool
    {
        return $this->acceptsSpeakerConnections();
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function polls()
    {
        return $this->hasMany(Poll::class);
    }

    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function reactions()
    {
        return $this->hasMany(Reaction::class);
    }

    public function feedback()
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * Event managers assigned to this event (B11). Super admins are not
     * listed here — Event::scopeVisibleTo() lets them through separately.
     */
    public function managers()
    {
        return $this->belongsToMany(User::class, 'event_user')->withPivot('role')->withTimestamps();
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Events the public site and attendee portal may still show: published
     * ones, plus `completed` ones (staff mark an event completed once it is
     * over, and attendees must keep their recording, resources and feedback
     * form afterwards). Draft and archived events stay hidden. Registration
     * stays published-only — see RegistrationController.
     */
    public function scopePubliclyVisible($query)
    {
        return $query->whereIn('status', ['published', 'completed']);
    }

    public function scopeUpcomingOrCurrent($query)
    {
        return $query->where('ends_at', '>=', now())->orderBy('starts_at');
    }

    /**
     * Hides archived events from every scope that doesn't explicitly ask
     * for them (B12/D5's Archive alternative to deletion).
     */
    public function scopeNotArchived($query)
    {
        return $query->where('status', '!=', 'archived');
    }

    /**
     * B11 — super admins see every event; event managers see only events
     * they are assigned to.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereHas('managers', fn ($q) => $q->where('users.id', $user->id));
    }

    public function isRegistrationOpen(): bool
    {
        return $this->status === 'published' && $this->ends_at?->isFuture();
    }
}
