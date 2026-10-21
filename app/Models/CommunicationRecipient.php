<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's delivery row for a Communication. `email` is the address at
 * send time, so the log still reads correctly if the person later changes it.
 */
class CommunicationRecipient extends Model
{
    protected $fillable = [
        'communication_id',
        'user_id',
        'email',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function communication()
    {
        return $this->belongsTo(Communication::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
