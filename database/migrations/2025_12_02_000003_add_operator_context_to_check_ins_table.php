<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B5 — the device/session context (ip_address, user_agent) the spec asks
 * CheckInService to record on every check-in. `checked_in_by`'s
 * nullability is handled by an earlier migration
 * (2025_11_30_000001_make_check_ins_checked_in_by_nullable), which must
 * run before 2025_12_01_000006's nullOnDelete() FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('checked_in_at');
            $table->string('user_agent', 500)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
