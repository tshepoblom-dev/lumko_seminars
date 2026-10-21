<?php

namespace App\Services;

use App\Jobs\SendCommunicationEmail;
use App\Models\Communication;
use App\Models\CommunicationRecipient;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Queues one Communication, modelled on AnnouncementMailer: a lock so a
 * double submit can't queue it twice, chunked so a large audience never
 * sits in memory, and an audit entry with the recipient count.
 *
 * Every delivery row is written before any job is dispatched, so the send
 * knows its full recipient count before the first job settles (with the
 * sync queue, jobs run inline). Jobs are spread out at
 * seminar.communications.per_minute so the SMTP host's rate cap isn't hit.
 */
class CommunicationSender
{
    /** @return int the number of people queued */
    public function send(Communication $communication, Builder $recipients): int
    {
        $lock = Cache::lock("communication-send:{$communication->id}", 120);

        if (! $lock->get()) {
            return 0;
        }

        try {
            (clone $recipients)
                ->select(['users.id', 'users.email'])
                ->chunkById(500, function ($users) use ($communication) {
                    $now = now();

                    CommunicationRecipient::insertOrIgnore($users->map(fn ($user) => [
                        'communication_id' => $communication->id,
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'status' => 'queued',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                }, 'users.id', 'id');

            $count = $communication->recipients()->count();
            $communication->update(['recipient_count' => $count, 'status' => 'sending']);

            Audit::record('communication.sent', subject: $communication, eventId: $communication->event_id, after: [
                'recipient_count' => $count,
                'category' => $communication->category->value,
                'recipient_mode' => $communication->recipient_mode,
                'filters' => $communication->filters,
                'attachments' => $communication->attachments->pluck('original_name')->all(),
            ]);

            $perMinute = max(1, (int) config('seminar.communications.per_minute', 60));
            $index = 0;

            $communication->recipients()->select('id')->chunkById(500, function ($rows) use ($perMinute, &$index) {
                foreach ($rows as $row) {
                    SendCommunicationEmail::dispatch($row->id)
                        ->delay(now()->addMinutes(intdiv($index, $perMinute)));
                    $index++;
                }
            });

            if ($count === 0) {
                $communication->settleIfComplete();
            }

            return $count;
        } finally {
            $lock->release();
        }
    }
}
