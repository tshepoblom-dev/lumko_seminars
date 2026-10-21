<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event photography for the public landing page: the hero image and the
 * venue band. Uploaded from the admin event form; stored on the public disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('hero_image_path')->nullable()->after('venue_address');
            $table->string('hero_image_alt')->nullable()->after('hero_image_path');
            $table->string('venue_image_path')->nullable()->after('hero_image_alt');
            $table->string('venue_image_alt')->nullable()->after('venue_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['hero_image_path', 'hero_image_alt', 'venue_image_path', 'venue_image_alt']);
        });
    }
};
