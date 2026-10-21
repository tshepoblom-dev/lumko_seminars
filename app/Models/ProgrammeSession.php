<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgrammeSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'sort_order',
        'status',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The session's speakers as one display line ("A", "A & B",
     * "A, B & C"), or null when none are named.
     */
    public static function speakerLine(self $session): ?string
    {
        $names = $session->speakers->pluck('name');

        if ($names->isEmpty()) {
            return null;
        }

        return $names->count() === 1 ? $names->first() : $names->slice(0, -1)->implode(', ').' & '.$names->last();
    }

    public function speakers()
    {
        return $this->belongsToMany(Speaker::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('speakers.name');
    }
}
