<?php

namespace App\Models;

use App\Enums\QuestionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;

    protected $fillable = ['event_id', 'user_id', 'programme_session_id', 'body', 'status', 'is_featured', 'answered_at', 'normalized_hash'];

    protected function casts(): array
    {
        return [
            'status' => QuestionStatus::class,
            'is_featured' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function scopeApproved($query)
    {
        return $query->whereIn('status', ['approved', 'answered']);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function votes()
    {
        return $this->hasMany(QuestionVote::class);
    }
}
