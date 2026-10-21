<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "How did you hear about us?" and the clients that came of it.
 *
 * - event_registrations.referral_source: a key from
 *   seminar.referral_sources, or `partner` with referred_by_partner_id.
 *   Naming a partner is attribution only; it is not consent to share the
 *   attendee's details with them (that's the separate, optional
 *   partner_contact consent).
 * - client_conversions: staff record that someone became a Lumko client.
 *   partner_id is the partner credited, snapshotted when it's recorded
 *   (pre-filled from the attendee's referral; staff can change it). Any
 *   reward to the partner is settled outside the platform.
 * - vouchers.partner_id: complimentary tickets a partner issued from its
 *   portal (the tier's comp_tickets cap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->string('referral_source', 40)->nullable();
            $table->foreignId('referred_by_partner_id')->nullable()->constrained('partners')->nullOnDelete();
        });

        Schema::create('client_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->date('converted_on');
            $table->unsignedBigInteger('deal_value_cents')->nullable();
            $table->string('notes', 2000)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('partner_id');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('event_id')->constrained('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });

        Schema::dropIfExists('client_conversions');

        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_partner_id');
            $table->dropColumn('referral_source');
        });
    }
};
