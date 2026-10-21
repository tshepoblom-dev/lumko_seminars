<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One page hit, written by App\Http\Middleware\RecordPageView. */
class PageView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'viewed_on',
        'viewed_at',
        'path',
        'route_name',
        'visitor_hash',
        'is_authenticated',
        'referrer_host',
    ];

    // `viewed_on` is deliberately not cast to a date: a date cast stores
    // "Y-m-d 00:00:00" on SQLite, and every analytics query groups and
    // matches on the plain "Y-m-d" day.
    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'is_authenticated' => 'boolean',
        ];
    }
}
