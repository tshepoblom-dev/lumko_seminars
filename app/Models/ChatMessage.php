<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A live-chat post beside the stream. Visible to everyone as soon as it's
 * posted; staff hide one afterwards (hidden_at), unlike Q&A questions,
 * which wait for approval.
 */
class ChatMessage extends Model
{
    protected $fillable = ['event_id', 'programme_session_id', 'user_id', 'body', 'hidden_at', 'hidden_by'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }

    /** The latest visible messages for an event, oldest first, ready to show. */
    public static function recentFor(Event $event, int $limit = 50): array
    {
        return static::where('event_id', $event->id)->visible()
            ->with('user:id,name')
            ->latest('id')->limit($limit)->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => $m->toPublicArray())
            ->values()
            ->all();
    }

    /**
     * What other attendees see: first name and last initial ("Thandi M."),
     * never the full name or email.
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => self::displayName($this->user?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function displayName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);

        if (! $parts) {
            return 'Attendee';
        }

        $first = $parts[0];
        $last = count($parts) > 1 ? mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : '';

        return trim("{$first} {$last}");
    }
}
