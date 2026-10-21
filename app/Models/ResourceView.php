<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceView extends Model
{
    public $timestamps = false;

    protected $fillable = ['resource_id', 'user_id', 'action', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(fn ($view) => $view->created_at ??= now());
    }

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
