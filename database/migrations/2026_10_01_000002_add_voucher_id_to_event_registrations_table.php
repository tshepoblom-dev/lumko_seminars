<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The voucher a registration was redeemed with — set means the in-person
 * ticket is complimentary. Unique, so one code can only ever become one
 * registration; restrict on delete, so the complimentary status can't be
 * lost silently (vouchers are revoked, never deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->unique()->constrained('vouchers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
            $table->dropUnique(['voucher_id']);
            $table->dropColumn('voucher_id');
        });
    }
};
