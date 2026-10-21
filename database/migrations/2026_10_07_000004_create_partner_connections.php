<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner leads, consent-only (owner decision, as for speakers):
 *
 * - consent_records.partner_id: a `partner_contact` ledger entry names the
 *   partner the attendee chose to share their details with.
 * - partner_connections: the current-state projection a partner's Leads
 *   page reads (written only by ConsentService). `source` is how the lead
 *   came in: connect | enquiry | badge_scan | registration.
 *   captured_by_user_id is the stand rep who scanned the ticket (the
 *   attendee still confirmed on the rep's device).
 * - partner_lead_notes: the partner team's own notes on a lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_records', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('speaker_id')->constrained('partners')->nullOnDelete();
        });

        Schema::create('partner_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('note', 1000)->nullable();
            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_id', 'partner_id']);
            $table->index(['partner_id', 'event_id', 'revoked_at']);
        });

        Schema::create('partner_lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_connection_id')->constrained('partner_connections')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('body', 2000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_lead_notes');
        Schema::dropIfExists('partner_connections');

        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });
    }
};
