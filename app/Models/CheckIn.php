<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckIn extends Model
{
    protected $fillable = ['ticket_id', 'checked_in_by', 'checked_in_at', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
