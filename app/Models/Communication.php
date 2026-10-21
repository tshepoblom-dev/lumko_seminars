<?php

namespace App\Models;

use App\Enums\CommunicationCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * One admin email send (App\Services\CommunicationSender). `event_id` null
 * means a platform-wide send to users across events.
 */
class Communication extends Model
{
    protected $fillable = [
        'event_id',
        'created_by',
        'subject',
        'body',
        'category',
        'recipient_mode',
        'filters',
        'status',
        'recipient_count',
        'sent_count',
        'failed_count',
        'skipped_count',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => CommunicationCategory::class,
            'filters' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments()
    {
        return $this->hasMany(CommunicationAttachment::class);
    }

    public function recipients()
    {
        return $this->hasMany(CommunicationRecipient::class);
    }

    public function isPlatformWide(): bool
    {
        return $this->event_id === null;
    }

    /**
     * Called after each recipient is settled: once every recipient is sent,
     * failed or skipped, the send gets its final status.
     */
    public function settleIfComplete(): void
    {
        $this->refresh();

        if ($this->sent_count + $this->failed_count + $this->skipped_count < $this->recipient_count) {
            return;
        }

        $status = match (true) {
            $this->failed_count === 0 => 'sent',
            $this->sent_count === 0 => 'failed',
            default => 'partially_failed',
        };

        $this->update(['status' => $status, 'sent_at' => $this->sent_at ?? now()]);
    }
}
