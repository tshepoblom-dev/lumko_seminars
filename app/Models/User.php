<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * `role` is cast by casts(); declared here so static analysis sees the enum
 * rather than a string.
 *
 * @property UserRole|null $role
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // 'role' is deliberately NOT fillable (B11) — mass assignment must
    // never be able to grant admin access. Roles are set only via
    // RoleService::assign(), which forceFill()s and audits the change.
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'mobile',
        'organisation',
        'job_title',
    ];

    // organisation_id is deliberately not fillable — it's derived from
    // `organisation` (Organisation::findOrCreateByName) rather than set
    // directly, so the two never drift apart.

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    protected static function booted(): void
    {
        // D-Registration — keeps organisation_id in sync with the free-text
        // organisation field automatically, so nothing has to remember to
        // call Organisation::findOrCreateByName() at every write site.
        static::saving(function (self $user) {
            if ($user->isDirty('organisation') && filled($user->organisation)) {
                $user->organisation_id = Organisation::findOrCreateByName($user->organisation)->id;
            } elseif ($user->isDirty('organisation') && blank($user->organisation)) {
                $user->organisation_id = null;
            }
        });
    }

    public function speakerProfile()
    {
        return $this->hasOne(Speaker::class);
    }

    /**
     * The partners whose portal this user may use (partner_members).
     * Portal access comes from these relationships, never from `role`.
     */
    public function partners()
    {
        return $this->belongsToMany(Partner::class, 'partner_members')
            ->withPivot('role')
            ->withTimestamps()
            ->orderBy('partners.name');
    }

    public function isSpeaker(): bool
    {
        return $this->speakerProfile()->exists();
    }

    public function isPartnerMember(): bool
    {
        return $this->partners()->exists();
    }

    public function registrations()
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function legalAcceptances()
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    public function consents()
    {
        return $this->hasMany(AttendeeConsent::class);
    }

    /** The append-only consent ledger (see ConsentService). */
    public function consentRecords()
    {
        return $this->hasMany(ConsentRecord::class);
    }

    public function feedback()
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * B11 — the events this staff member (event manager) is assigned to
     * via the event_user pivot. Inverse of Event::managers(). Super
     * admins aren't listed here; they bypass assignment checks entirely
     * via isSuperAdmin() wherever this is consulted.
     */
    public function managedEvents()
    {
        return $this->belongsToMany(Event::class, 'event_user')->withPivot('role')->withTimestamps();
    }

    public function organisationRecord()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isEventManager(): bool
    {
        return $this->role === UserRole::EventManager;
    }

    public function isAttendee(): bool
    {
        return $this->role === UserRole::Attendee;
    }

    public function isStaff(): bool
    {
        return $this->role?->isStaff() ?? false;
    }
}
