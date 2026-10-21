<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3 — secondary/fallback livestream provider, a manually-set post-event
 * recording link, whether attendee-visible reaction counts are shown on
 * /live, and how many minutes before starts_at the Agora audience token
 * endpoint opens up (staff always bypass this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('livestream_secondary_provider')->nullable()->after('livestream_channel_name');
            $table->string('livestream_secondary_url')->nullable()->after('livestream_secondary_provider');
            $table->string('recording_url')->nullable()->after('livestream_secondary_url');
            $table->boolean('show_reactions_to_attendees')->default(false)->after('recording_url');
            $table->unsignedSmallInteger('live_lead_minutes')->default(15)->after('show_reactions_to_attendees');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'livestream_secondary_provider', 'livestream_secondary_url',
                'recording_url', 'show_reactions_to_attendees', 'live_lead_minutes',
            ]);
        });
    }
};
