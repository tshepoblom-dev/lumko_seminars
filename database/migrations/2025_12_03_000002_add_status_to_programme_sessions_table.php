<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** C6 — control-room "Start session"/"End session" need session-level state. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programme_sessions', function (Blueprint $table) {
            $table->string('status')->default('scheduled')->after('sort_order');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('ended_at')->nullable()->after('started_at');
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('programme_sessions', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'status']);
            $table->dropColumn(['status', 'started_at', 'ended_at']);
        });
    }
};
