<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('livestream_provider')->nullable()->after('status');
            $table->string('livestream_url')->nullable()->after('livestream_provider');
            $table->string('livestream_channel_name')->nullable()->after('livestream_url');
            $table->foreignId('current_session_id')->nullable()->after('livestream_channel_name')
                ->constrained('programme_sessions')->nullOnDelete();
            $table->boolean('is_live')->default(false)->after('current_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_session_id');
            $table->dropColumn(['livestream_provider', 'livestream_url', 'livestream_channel_name', 'is_live']);
        });
    }
};
