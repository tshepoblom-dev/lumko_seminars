<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vouchers: honorary invitation codes. Each one is good for exactly one
 * complimentary in-person ticket at its event.
 *
 * - code: encrypted at rest (admins can copy or re-send it); every lookup
 *   goes through code_hash, an HMAC of the normalised code (App\Support\
 *   VoucherCode), like tickets.code / tickets.code_hash.
 * - email_locked: only recipient_email may redeem it; otherwise whoever
 *   holds the code can.
 * - redeemed_at / revoked_at are each claimed with a conditional update, so
 *   a code can never be both, nor redeemed twice. Which registration it
 *   became lives on event_registrations.voucher_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->text('code');
            $table->string('code_hash', 64)->unique();
            $table->string('recipient_name');
            $table->string('recipient_email')->nullable();
            $table->boolean('email_locked')->default(false);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
