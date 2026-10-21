<?php

namespace App\Models;

use App\Enums\AnnouncementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = ['event_id', 'created_by', 'title', 'body', 'type', 'audience', 'published_at', 'emailed_at', 'send_started_at'];

    protected function casts(): array
    {
        return [
            'type' => AnnouncementType::class,
            'published_at' => 'datetime',
            'emailed_at' => 'datetime',
            'send_started_at' => 'datetime',
        ];
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
