<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-Announcements — audience targeting + a scheduled-publish tick. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('audience')->default('all')->after('type');
            $table->timestamp('send_started_at')->nullable()->after('emailed_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['audience', 'send_started_at']);
        });
    }
};
