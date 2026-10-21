<?php

namespace App\Jobs;

use App\Enums\CommunicationCategory;
use App\Enums\ConsentType;
use App\Models\CommunicationRecipient;
use App\Notifications\CommunicationNotification;
use App\Services\ConsentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one Communication to one person and records the outcome on their
 * delivery row. One job per person means one bad address fails alone and
 * the admin sees exactly who didn't get it.
 */
class SendCommunicationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $recipientId) {}

    public function handle(ConsentService $consents): void
    {
        $recipient = CommunicationRecipient::with(['communication.attachments', 'user'])->find($this->recipientId);

        if (! $recipient || $recipient->status !== 'queued') {
            return;
        }

        $communication = $recipient->communication;
        $user = $recipient->user;

        // Consent can be withdrawn between clicking Send and this job
        // running, so marketing is re-checked against the ledger here.
        $noConsent = $communication->category === CommunicationCategory::Marketing
            && (! $user || ! $consents->isGranted($user, ConsentType::LumkoMarketing));

        if (! $user || $noConsent) {
            $this->settle($recipient, 'skipped_no_consent', 'skipped_count', $user ? null : 'Account deleted');

            return;
        }

        Notification::sendNow($user, CommunicationNotification::for($communication));

        $this->settle($recipient, 'sent', 'sent_count');
    }

    /** Only after the last retry: an SMTP rejection, a missing attachment… */
    public function failed(?Throwable $exception): void
    {
        $recipient = CommunicationRecipient::find($this->recipientId);

        if ($recipient && $recipient->status === 'queued') {
            $this->settle($recipient, 'failed', 'failed_count', Str::limit((string) $exception?->getMessage(), 480));
        }
    }

    private function settle(CommunicationRecipient $recipient, string $status, string $counter, ?string $error = null): void
    {
        $recipient->update([
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);

        $recipient->communication->increment($counter);
        $recipient->communication->settleIfComplete();
    }
}
