<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Offer online attendance" (event settings). Off: registration offers in
 * person only and the public pages don't mention online; people already
 * registered online keep their registration and can switch to in person
 * from their dashboard. Off by default, existing events included: owner
 * decision 2026-10-21, events are in person (The Power of Data is recorded
 * for release afterwards), and online is something staff turn on per event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('offers_online')->default(false)->after('in_person_price');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('offers_online');
        });
    }
};
