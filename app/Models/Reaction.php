<?php

namespace App\Models;

use App\Enums\ReactionType;
use Illuminate\Database\Eloquent\Model;

class Reaction extends Model
{
    protected $fillable = ['event_id', 'programme_session_id', 'user_id', 'type'];

    protected function casts(): array
    {
        return ['type' => ReactionType::class];
    }
}
