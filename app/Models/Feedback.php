<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    use HasFactory;

    protected $table = 'feedback';

    protected $fillable = [
        'event_id', 'user_id', 'rating', 'biggest_takeaway',
        'topics_next', 'wants_contact', 'contact_topics',
    ];

    protected function casts(): array
    {
        return [
            'topics_next' => 'array',
            'contact_topics' => 'array',
            'wants_contact' => 'boolean',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
