<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-Registration — step 2 of the stepper (optional sector/org size). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->string('industry')->nullable()->after('improvement_area');
            $table->string('organisation_size')->nullable()->after('industry');
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropColumn(['industry', 'organisation_size']);
        });
    }
};
