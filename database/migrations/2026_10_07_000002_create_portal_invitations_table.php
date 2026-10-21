<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to the speaker and partner portals (PortalInvitationService).
 * The raw token exists only in the email; this table keeps its sha256
 * hash, like event_speaker.report_token_hash. A token is single use:
 * accepted_at is claimed with a conditional update.
 *
 * Also makes speakers.user_id unique: one account is at most one speaker
 * (User::speakerProfile() is a hasOne).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16); // speaker | partner
            $table->string('email');
            $table->foreignId('speaker_id')->nullable()->constrained('speakers')->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->cascadeOnDelete();
            $table->string('member_role', 16)->nullable(); // partner invitations: owner | member
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('email');
        });

        Schema::table('speakers', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });

        Schema::dropIfExists('portal_invitations');
    }
};
