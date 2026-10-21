<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Insert-only. Nothing in this codebase updates or deletes a row here —
 * see App\Support\Audit, the only writer.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'subject_type',
        'subject_id',
        'event_id',
        'before',
        'after',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            $log->created_at ??= now();
        });
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
