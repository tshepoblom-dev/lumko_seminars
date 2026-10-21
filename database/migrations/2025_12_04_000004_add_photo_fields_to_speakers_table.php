<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-Programme data — alt text (accessibility) + a manual approval gate before a photo shows publicly. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->string('photo_alt')->nullable()->after('photo_path');
            $table->boolean('photo_approved')->default(false)->after('photo_alt');
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->dropColumn(['photo_alt', 'photo_approved']);
        });
    }
};
