<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    use HasFactory;

    protected $fillable = ['event_id', 'requester_id', 'recipient_id', 'status'];

    protected function casts(): array
    {
        return ['status' => ConnectionStatus::class];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
