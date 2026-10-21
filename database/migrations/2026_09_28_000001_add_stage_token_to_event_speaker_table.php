<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A remote speaker joins the Agora channel as a presenter through a stage
 * link (/stage/{token}) — speakers aren't user accounts. Only the sha256 of
 * the token is stored; staff see the raw link once, when it's created.
 * Regenerating replaces the hash and revoking clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_speaker', function (Blueprint $table) {
            $table->string('stage_token_hash', 64)->nullable()->unique()->after('sort_order');
            $table->timestamp('stage_token_created_at')->nullable()->after('stage_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('event_speaker', function (Blueprint $table) {
            $table->dropUnique(['stage_token_hash']);
            $table->dropColumn(['stage_token_hash', 'stage_token_created_at']);
        });
    }
};
