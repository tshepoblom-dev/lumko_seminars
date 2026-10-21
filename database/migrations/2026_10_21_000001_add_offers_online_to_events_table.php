<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Offer online attendance" (event settings). Off: registration offers in
 * person only and the public pages don't mention online; people already
 * registered online keep their registration and can switch to in person
 * from their dashboard. On by default, so nothing changes until staff
 * turn it off (owner decision 2026-10-21: The Power of Data became in
 * person only, recorded for release afterwards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('offers_online')->default(true)->after('in_person_price');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('offers_online');
        });
    }
};
