<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One acceptance of one version of the Terms or the Privacy Policy (append-only). */
class LegalAcceptance extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'accepted_at';

    protected $fillable = [
        'user_id',
        'document',
        'version',
        'source',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
