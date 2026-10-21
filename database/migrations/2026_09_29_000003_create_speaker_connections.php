<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speaker connections: an attendee chooses to share their contact details
 * with one speaker (never a list handover), and the speaker receives those
 * people plus a session report through an expiring link.
 *
 * - consent_records.speaker_id: which speaker a `speaker_contact` ledger
 *   entry is about. The rendered purpose_text names the speaker too, so the
 *   record still reads correctly if the speaker is later deleted.
 * - speaker_connections: the current-state projection (like
 *   attendee_consents, whose one-row-per-type key can't hold per-speaker rows).
 * - speakers.contact_email: where the report link goes. Admin-only.
 * - event_speaker report link: only a sha256 hash of the token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_records', function (Blueprint $table) {
            $table->foreignId('speaker_id')->nullable()->after('event_id')->constrained('speakers')->nullOnDelete();
        });

        Schema::create('speaker_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('speaker_id')->constrained('speakers')->cascadeOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_id', 'speaker_id']);
            $table->index(['event_id', 'speaker_id', 'revoked_at']);
        });

        Schema::table('speakers', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->after('organisation');
        });

        Schema::table('event_speaker', function (Blueprint $table) {
            $table->string('report_token_hash', 64)->nullable()->unique();
            $table->timestamp('report_token_expires_at')->nullable();
            $table->timestamp('report_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_speaker', function (Blueprint $table) {
            $table->dropUnique(['report_token_hash']);
            $table->dropColumn(['report_token_hash', 'report_token_expires_at', 'report_sent_at']);
        });

        Schema::table('speakers', function (Blueprint $table) {
            $table->dropColumn('contact_email');
        });

        Schema::dropIfExists('speaker_connections');

        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('speaker_id');
        });
    }
};
