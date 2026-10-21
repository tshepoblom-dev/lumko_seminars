<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** C6 — optional scheduled open/close for a poll, ticked by polls:tick. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->timestamp('opens_at')->nullable()->after('status');
            $table->timestamp('closes_at')->nullable()->after('opens_at');
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'status']);
            $table->dropColumn(['opens_at', 'closes_at']);
        });
    }
};
