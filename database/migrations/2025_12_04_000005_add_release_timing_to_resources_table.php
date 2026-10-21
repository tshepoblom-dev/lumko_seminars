<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-Resources — `is_published` stays the manual master switch; these add
 * *when* a published resource actually becomes visible to attendees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->string('release_mode')->default('immediate')->after('category');
            $table->timestamp('release_at')->nullable()->after('release_mode');
            $table->foreignId('programme_session_id')->nullable()->after('release_at')
                ->constrained('programme_sessions')->nullOnDelete();
            $table->timestamp('released_at')->nullable()->after('programme_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('programme_session_id');
            $table->dropColumn(['release_mode', 'release_at', 'released_at']);
        });
    }
};
