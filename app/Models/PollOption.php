<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PollOption extends Model
{
    protected $fillable = ['poll_id', 'label', 'sort_order'];

    public function poll()
    {
        return $this->belongsTo(Poll::class);
    }

    public function responses()
    {
        return $this->hasMany(PollResponse::class);
    }
}
