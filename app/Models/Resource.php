<?php

namespace App\Models;

use App\Enums\ResourceCategory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'created_by', 'speaker_id', 'partner_id', 'title', 'description', 'category',
        'file_path', 'file_original_name', 'external_url',
        'is_published', 'published_at',
        'release_mode', 'release_at', 'programme_session_id', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => ResourceCategory::class,
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'release_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function programmeSession()
    {
        return $this->belongsTo(ProgrammeSession::class);
    }

    /** Set when the speaker uploaded it from the speaker portal. */
    public function speaker()
    {
        return $this->belongsTo(Speaker::class);
    }

    /** Set when a partner uploaded it from its portal. */
    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function views()
    {
        return $this->hasMany(ResourceView::class);
    }

    /**
     * D-Resources — `is_published` is the manual master switch; this is
     * the *timing* rule on top of it. `immediate` is available the
     * moment it's published; `before_event`/`release_at` waits for a
     * specific timestamp; `during_session` waits for a specific
     * programme session to become the event's current one.
     */
    public function isReleased(Event $event, ?ProgrammeSession $currentSession, CarbonInterface $now): bool
    {
        if (! $this->is_published) {
            return false;
        }

        return match ($this->release_mode) {
            'before_event', 'immediate' => ! $this->release_at || $this->release_at->lte($now),
            'during_session' => $this->programme_session_id && $currentSession?->id === $this->programme_session_id,
            'after_event' => $event->ends_at?->lte($now) ?? false,
            default => true,
        };
    }

    /**
     * D-Resources — the doc's `Resource::availableFor()`. A plain static
     * method rather than an Eloquent local scope (scopeX), since it
     * returns an already-filtered Collection, not a chainable Builder.
     */
    public static function availableFor(Event $event, ?ProgrammeSession $currentSession, CarbonInterface $now)
    {
        return static::where('event_id', $event->id)->published()->get()->filter(
            fn (self $resource) => $resource->isReleased($event, $currentSession, $now)
        )->values();
    }
}
