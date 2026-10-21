<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * B3 — deletes proof-of-payment *files* for events that ended more than
 * `seminar.pop_retention_days` ago. The payment record itself (amount,
 * status, history) is kept forever as the financial record; only the
 * uploaded file is removed.
 */
class PurgePaymentProofsCommand extends Command
{
    protected $signature = 'payments:purge-proofs';

    protected $description = 'Delete proof-of-payment files older than the configured retention window';

    public function handle(): int
    {
        $retentionDays = (int) config('seminar.pop_retention_days', 180);
        $cutoff = now()->subDays($retentionDays);

        $payments = Payment::query()
            ->whereNotNull('proof_path')
            ->whereHas('registration.event', fn ($q) => $q->where('ends_at', '<=', $cutoff))
            ->with('registration.event')
            ->get();

        $deleted = 0;

        foreach ($payments as $payment) {
            $path = $payment->proof_path;

            Storage::disk('local')->delete($path);
            $payment->update(['proof_path' => null, 'proof_original_name' => null]);

            Audit::record(
                'payment.proof_purged',
                subject: $payment,
                eventId: $payment->registration?->event_id,
                before: ['proof_path' => $path],
            );

            $deleted++;
        }

        $this->components->info("Purged {$deleted} proof-of-payment file(s) older than {$retentionDays} days past event end.");

        return self::SUCCESS;
    }
}
