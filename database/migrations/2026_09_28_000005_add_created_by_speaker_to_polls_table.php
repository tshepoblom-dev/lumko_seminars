<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speakers create polls from their stage link. They aren't user accounts,
 * so created_by (users) can't hold them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->foreignId('created_by_speaker_id')->nullable()->after('created_by')->constrained('speakers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_speaker_id');
        });
    }
};
