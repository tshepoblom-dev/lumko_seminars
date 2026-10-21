<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A partner team member's own note on a lead (never shown to the attendee). */
class PartnerLeadNote extends Model
{
    protected $fillable = ['partner_connection_id', 'user_id', 'body'];

    public function connection()
    {
        return $this->belongsTo(PartnerConnection::class, 'partner_connection_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
