<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Staff's record that an attendee became a Lumko client, crediting the
 * partner (if any) that referred them. Partners only ever see counts.
 */
class ClientConversion extends Model
{
    protected $fillable = [
        'user_id',
        'organisation_id',
        'partner_id',
        'event_id',
        'converted_on',
        'deal_value_cents',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'converted_on' => 'date',
            'deal_value_cents' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
