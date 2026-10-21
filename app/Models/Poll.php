<?php

namespace App\Models;

use App\Enums\PollStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Poll extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'programme_session_id', 'created_by', 'created_by_speaker_id', 'question',
        'status', 'show_results_live', 'opened_at', 'closed_at',
        'opens_at', 'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PollStatus::class,
            'show_results_live' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    public function options()
    {
        return $this->hasMany(PollOption::class)->orderBy('sort_order');
    }

    public function responses()
    {
        return $this->hasMany(PollResponse::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
