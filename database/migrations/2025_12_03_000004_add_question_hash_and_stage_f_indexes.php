<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C6 — `normalized_hash` supports duplicate-question detection (same
 * user, normalised-text hash within 5 minutes). Also lands the Stage F
 * index list that hadn't been added yet: reactions(event_id,created_at),
 * resource_views(resource_id,user_id), connections(event_id,recipient_id,status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('normalized_hash', 64)->nullable()->after('body');
            $table->index(['event_id', 'status']);
            $table->index(['user_id', 'normalized_hash']);
        });

        Schema::table('reactions', function (Blueprint $table) {
            $table->index(['event_id', 'created_at']);
        });

        Schema::table('resource_views', function (Blueprint $table) {
            $table->index(['resource_id', 'user_id']);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->index(['event_id', 'recipient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'status']);
            $table->dropIndex(['user_id', 'normalized_hash']);
            $table->dropColumn('normalized_hash');
        });

        Schema::table('reactions', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'created_at']);
        });

        Schema::table('resource_views', function (Blueprint $table) {
            $table->dropIndex(['resource_id', 'user_id']);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'recipient_id', 'status']);
        });
    }
};
