<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A file sent with a Communication, on the private `local` disk. */
class CommunicationAttachment extends Model
{
    protected $fillable = [
        'communication_id',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    public function communication()
    {
        return $this->belongsTo(Communication::class);
    }
}
