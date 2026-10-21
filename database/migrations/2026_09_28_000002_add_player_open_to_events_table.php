<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Open the player now" (Control room): lets registered attendees join the
 * Agora player before the scheduled lead window — for rehearsals and tests
 * ahead of the event day. It doesn't make the event live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('player_open')->default(false)->after('is_live');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('player_open');
        });
    }
};
